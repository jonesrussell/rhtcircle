<?php

declare(strict_types=1);

namespace App\Controller;

use App\Poll\PollRepository;
use App\Support\View;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public surface for anonymous, single-select community polls.
 *
 * A vote is never a per-voter row (see PollRepository / PollSchema): the only
 * per-browser state is a "which option did you pick" cookie, used purely so a
 * repeat page view (or a repeat submit) shows results instead of the form
 * again. It is read server-side only as "has this browser voted on this
 * poll"; it is never logged and never stored in the database.
 */
final class PollController
{
    private const MAX_BODY_BYTES = 2048;
    private const COOKIE_PREFIX = 'poll_v_';
    private const COOKIE_TTL_DAYS = 365;

    public function __construct(private readonly PollRepository $polls) {}

    /** GET a poll page. $template is the Twig template for this specific poll. */
    public function page(Request $request, string $slug, string $template): Response
    {
        $poll = $this->polls->findActivePoll($slug);
        if ($poll === null) {
            return new Response('Not found', 404);
        }

        $pollId = (int) $poll['id'];
        $votedOptionId = $this->votedOption($request, $slug, $pollId);

        return $this->render($template, [
            'question' => (string) $poll['question'],
            'options' => $this->polls->options($pollId),
            'has_voted' => $votedOptionId !== null,
            'voted_option_id' => $votedOptionId,
            'results' => $votedOptionId !== null ? $this->polls->results($pollId) : null,
        ]);
    }

    /**
     * POST /api/poll/vote — JSON body {slug, option_id, website}. A JSON body
     * (like the petition/contact/analytics endpoints) skips the framework's
     * CSRF guard; there is no session or account tying a vote to a person to
     * protect in the first place.
     */
    public function vote(Request $request): Response
    {
        $raw = $request->getContent();
        if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->fail('That did not look right. Please try again.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->fail('That did not look right. Please try again.');
        }

        // Honeypot: a real person leaves this hidden field empty. Answer as
        // if it worked but store nothing.
        if (trim((string) ($data['website'] ?? '')) !== '') {
            return new JsonResponse(['ok' => true, 'honey' => true]);
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        $poll = $slug !== '' ? $this->polls->findActivePoll($slug) : null;
        if ($poll === null) {
            return $this->fail('This poll is not open right now.', 404);
        }
        $pollId = (int) $poll['id'];

        // Already voted, per this browser's cookie: answer with the current
        // results and do not count again, rather than error. Stateless
        // dedup; nothing is looked up or stored server-side to reach this.
        if ($this->votedOption($request, $slug, $pollId) !== null) {
            return new JsonResponse(['ok' => true, 'results' => $this->polls->results($pollId)]);
        }

        $optionId = (int) ($data['option_id'] ?? 0);
        if (!$this->polls->isValidOption($pollId, $optionId)) {
            return $this->fail('Please choose one option.');
        }

        if ($this->polls->tooManyFromIp($request->getClientIp())) {
            return $this->fail('Too many votes from here just now. Please try again later.', 429);
        }

        $this->polls->castVote($optionId);
        $this->polls->recordAttempt($request->getClientIp());

        $response = new JsonResponse(['ok' => true, 'results' => $this->polls->results($pollId)]);
        $response->headers->setCookie(Cookie::create(
            name: self::COOKIE_PREFIX . $slug,
            value: (string) $optionId,
            expire: time() + self::COOKIE_TTL_DAYS * 86400,
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));

        return $response;
    }

    /**
     * GET a page holding several independent one-question polls (e.g. a
     * two-question ballot). Each slug is still its own poll row with its own
     * options, votes, and vote cookie; this only groups them onto one page.
     * A slug that is missing or inactive is silently skipped rather than
     * 404ing the whole page.
     *
     * @param list<string> $slugs
     */
    public function pageMulti(Request $request, array $slugs, string $template): Response
    {
        $questions = [];
        foreach ($slugs as $slug) {
            $poll = $this->polls->findActivePoll($slug);
            if ($poll === null) {
                continue;
            }

            $pollId = (int) $poll['id'];
            $votedOptionId = $this->votedOption($request, $slug, $pollId);

            $questions[] = [
                'slug' => $slug,
                'question' => (string) $poll['question'],
                'options' => $this->polls->options($pollId),
                'has_voted' => $votedOptionId !== null,
                'voted_option_id' => $votedOptionId,
                'results' => $votedOptionId !== null ? $this->polls->results($pollId) : null,
            ];
        }

        return $this->render($template, ['questions' => $questions]);
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * The option this browser already voted for on this poll, if any and
     * still valid (guards against a poll's options changing after launch).
     */
    private function votedOption(Request $request, string $slug, int $pollId): ?int
    {
        $raw = $request->cookies->get(self::COOKIE_PREFIX . $slug);
        if ($raw === null || $raw === '') {
            return null;
        }
        $optionId = (int) $raw;

        return $this->polls->isValidOption($pollId, $optionId) ? $optionId : null;
    }

    private function fail(string $message, int $statusCode = 422): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $statusCode);
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
