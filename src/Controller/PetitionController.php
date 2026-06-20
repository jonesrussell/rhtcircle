<?php

declare(strict_types=1);

namespace App\Controller;

use App\Petition\PetitionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Support\View;

/**
 * Public surface for the "Add your voice" petition system.
 *
 * Signature data is written only to the Circle's own database (see PetitionRepository
 * / PetitionSchema). This controller does the request handling and validation;
 * it builds no SQL. The sign endpoint takes a JSON body (so the framework's CSRF
 * guard is skipped, matching the chat and analytics endpoints) and answers JSON.
 */
final class PetitionController
{
    private const MAX_BODY_BYTES = 4096;
    private const NAME_MAX = 120;
    private const COMMENT_MAX = 2000;

    public function __construct(private readonly PetitionRepository $petitions) {}

    /**
     * POST /api/petition/sign — store a signature, return the live count and a
     * personal remove link. Anti-abuse: hidden honeypot, per-ip_hash rate limit.
     */
    public function sign(Request $request): Response
    {
        $raw = $request->getContent();
        if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->fail('That did not look right. Please try again.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->fail('That did not look right. Please try again.');
        }

        // Honeypot: a real person leaves this hidden field empty. If it is
        // filled, answer as if it worked but store nothing.
        if (trim((string) ($data['website'] ?? '')) !== '') {
            return new JsonResponse(['ok' => true, 'count' => null, 'honey' => true]);
        }

        $slug = trim((string) ($data['campaign'] ?? ''));
        $campaign = $slug !== '' ? $this->petitions->findActiveCampaign($slug) : null;
        if ($campaign === null) {
            return $this->fail('This campaign is not open for signatures.', 404);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $memberFlag = ((string) ($data['member_flag'] ?? 'supporter')) === 'member' ? 'member' : 'supporter';
        $comment = trim((string) ($data['comment'] ?? ''));
        $showName = (bool) ($data['show_name_publicly'] ?? false);
        $includeOnLetter = (bool) ($data['include_name_on_letter'] ?? false);
        $consent = (bool) ($data['consent'] ?? false);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            return $this->fail('Please enter your name.');
        }
        // Email is optional; validate the format only when one is given.
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Please enter a valid email address, or leave it blank.');
        }
        if (!$consent) {
            return $this->fail('Please confirm your consent to add your voice.');
        }
        if (mb_strlen($comment) > self::COMMENT_MAX) {
            $comment = mb_substr($comment, 0, self::COMMENT_MAX);
        }

        if ($this->petitions->tooManyFromIp($request->getClientIp())) {
            return $this->fail('Too many sign-ups from here just now. Please try again later.', 429);
        }

        $result = $this->petitions->recordSignature(
            campaignId: (int) $campaign['id'],
            name: $name,
            email: $email,
            memberFlag: $memberFlag,
            comment: $comment !== '' ? $comment : null,
            showNamePublicly: $showName,
            includeNameOnLetter: $includeOnLetter,
            consent: $consent,
            ip: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
        );

        return new JsonResponse([
            'ok' => true,
            'status' => $result['status'],
            'count' => $this->petitions->publicCount($campaign),
            'manage_url' => '/petition/remove/' . $result['token'],
            'recipient' => (string) $campaign['recipient'],
        ]);
    }

    /**
     * GET /api/petition/{slug} — public campaign info + live count. Drives the
     * partial's live count and lets it hydrate from just a slug. No personal
     * data is exposed: supporter names are NOT returned (no public visibility of
     * who signed in this phase), only the aggregate count.
     */
    public function info(string $slug): Response
    {
        $campaign = $this->petitions->findActiveCampaign($slug);
        if ($campaign === null) {
            return new JsonResponse(['ok' => false], 404);
        }

        return new JsonResponse([
            'ok' => true,
            'slug' => (string) $campaign['slug'],
            'title' => (string) $campaign['title'],
            'the_ask' => (string) $campaign['the_ask'],
            'recipient' => (string) $campaign['recipient'],
            'count' => $this->petitions->publicCount($campaign),
            // Aggregate count of signatures collected on paper and handed in,
            // included in `count`, plus a dated public provenance note.
            'paper_count' => (int) ($campaign['paper_count'] ?? 0),
            'paper_note' => (string) ($campaign['paper_note'] ?? ''),
            // Privacy-safe: only signers who chose "show my name publicly",
            // as first name + last initial. Pages may or may not render these.
            'recent' => $this->petitions->recentPublicSupporters((int) $campaign['id']),
        ]);
    }

    /** GET /petition/remove/{token} — one-click removal, themed result page. */
    public function remove(string $token): Response
    {
        $campaign = $this->petitions->remove($token);

        return $this->renderResult([
            'kind' => $campaign !== null ? 'removed' : 'notfound',
            'campaign' => $campaign,
        ]);
    }

    /** GET /petition/privacy — the petition-system privacy notice. */
    public function privacy(): Response
    {
        return $this->render('privacy/petition.html.twig', []);
    }

    // ---- helpers ---------------------------------------------------------

    private function fail(string $message, int $statusCode = 422): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $statusCode);
    }

    /** @param array<string, mixed> $context */
    private function renderResult(array $context): Response
    {
        return $this->render('petition/result.html.twig', $context);
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context): Response
    {
        return new Response(
            View::render($template, $context),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
