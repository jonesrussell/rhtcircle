<?php

declare(strict_types=1);

namespace App\Controller;

use App\Signup\SignupRepository;
use App\Support\View;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member-owned email list: signup and one-click remove.
 *
 * COLLECT-ONLY (see working/cc-prompt-rhtcircle-list.md): no confirmation
 * email, no sending, no ESP. Single opt-in with express consent -- the exact
 * checkbox text shown, and when, is stored as the CASL consent proof
 * (SignupRepository::store()). TODO(send): when send infrastructure exists,
 * either rely on this stored consent or run a one-time confirmation pass.
 *
 * Built on the contact/petition pattern: JSON body POST (CSRF guard skipped,
 * same as contact/petition/analytics), hidden honeypot, per-ip_hash rate limit.
 */
final class SignupController
{
    private const int MAX_BODY_BYTES = 4096;
    private const int NAME_MAX = 120;
    private const int NATION_MAX = 120;
    public const string CONSENT_TEXT_VERSION = '2026-07-03-v1';
    public const string CONSENT_TEXT =
        'Yes, send me updates from the RHT Circle about accountability and our shared settlement. '
        . 'I can unsubscribe anytime.';

    public function __construct(private readonly SignupRepository $signup) {}

    /** GET /updates — render the dedicated signup page. */
    public function page(): Response
    {
        return new Response(
            View::render('pages/signup.html.twig', [
                'consent_text' => self::CONSENT_TEXT,
            ]),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * POST /api/signup — store an express-consent signup. Returns JSON
     * including a one-click remove link (built from the stored remove_token)
     * so the success message can offer it immediately.
     */
    public function submit(Request $request): Response
    {
        $raw = $request->getContent();
        if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->fail('That did not look right. Please try again.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->fail('That did not look right. Please try again.');
        }

        // Honeypot: a real person leaves this hidden field empty.
        if (trim((string) ($data['website'] ?? '')) !== '') {
            return new JsonResponse(['ok' => true, 'honey' => true]);
        }

        $email = trim((string) ($data['email'] ?? ''));
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $nation = trim((string) ($data['nation'] ?? ''));
        $consent = (bool) ($data['consent'] ?? false);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Please enter a valid email address.');
        }
        if (!$consent) {
            return $this->fail('Please check the consent box so we know it is really you opting in.');
        }
        if (mb_strlen($firstName) > self::NAME_MAX) {
            $firstName = mb_substr($firstName, 0, self::NAME_MAX);
        }
        if (mb_strlen($nation) > self::NATION_MAX) {
            $nation = mb_substr($nation, 0, self::NATION_MAX);
        }

        if ($this->signup->tooManyFromIp($request->getClientIp())) {
            return $this->fail('Too many signups from here just now. Please try again later.', 429);
        }

        $token = $this->signup->store(
            email: $email,
            firstName: $firstName !== '' ? $firstName : null,
            nation: $nation !== '' ? $nation : null,
            consentTextVersion: self::CONSENT_TEXT_VERSION,
            ip: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
        );

        return new JsonResponse([
            'ok' => true,
            'remove_url' => '/updates/remove?token=' . urlencode($token),
        ]);
    }

    /**
     * GET /updates/remove?token=... — one-click unsubscribe (no login, no
     * confirmation page needed for something this low-stakes: honored
     * immediately, same shape as the petition's remove link).
     */
    public function remove(Request $request): Response
    {
        $token = trim((string) $request->query->get('token', ''));
        if ($token === '') {
            return new Response('Missing token.', 400);
        }

        $removed = $this->signup->removeByToken($token);

        return new Response(
            View::render('pages/signup-removed.html.twig', ['removed' => $removed]),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function fail(string $message, int $statusCode = 422): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $statusCode);
    }
}
