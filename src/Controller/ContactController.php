<?php

declare(strict_types=1);

namespace App\Controller;

use App\Contact\ContactRepository;
use App\Support\View;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public contact form.
 *
 * Messages are written only to the Circle's own database (see ContactRepository
 * / ContactSchema), on the Circle's servers in Canada. Built on the petition
 * pattern: the page renders the form, and the submit endpoint takes a JSON body
 * (so the framework CSRF guard is skipped, matching the petition and analytics
 * endpoints) and answers JSON. Anti-abuse: a hidden honeypot and a per-ip_hash
 * rate limit. No mailer is wired yet, so messages are stored and listed in the
 * gated admin; wiring an email notification is a follow-up.
 */
final class ContactController
{
    private const int MAX_BODY_BYTES = 8192;
    private const int NAME_MAX = 120;
    private const int MESSAGE_MAX = 4000;

    public function __construct(private readonly ContactRepository $contact) {}

    /** GET /contact — render the form page. */
    public function page(): Response
    {
        return new Response(
            View::render('pages/contact.html.twig'),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * POST /api/contact — store a message. Anti-abuse: hidden honeypot,
     * per-ip_hash rate limit. Returns JSON.
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

        // Honeypot: a real person leaves this hidden field empty. If it is
        // filled, answer as if it worked but store nothing.
        if (trim((string) ($data['website'] ?? '')) !== '') {
            return new JsonResponse(['ok' => true, 'honey' => true]);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $kind = (string) ($data['kind'] ?? 'other');
        $message = trim((string) ($data['message'] ?? ''));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            return $this->fail('Please enter your name.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Please enter a valid email address so we can reply.');
        }
        if ($message === '') {
            return $this->fail('Please enter a message.');
        }
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            $message = mb_substr($message, 0, self::MESSAGE_MAX);
        }

        if ($this->contact->tooManyFromIp($request->getClientIp())) {
            return $this->fail('Too many messages from here just now. Please try again later.', 429);
        }

        $this->contact->store(
            name: $name,
            email: $email,
            kind: $kind,
            message: $message,
            ip: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
        );

        return new JsonResponse(['ok' => true]);
    }

    private function fail(string $message, int $statusCode = 422): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $statusCode);
    }
}
