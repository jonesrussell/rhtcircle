<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lexicon\LexiconClient;
use App\Support\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "look up a word in Anishinaabemowin" search on /treaty/language.
 *
 * Server-rendered and stateless: the form GETs back to the same page with ?q=,
 * the controller calls {@see LexiconClient} server-side (Minoo has no CORS, so
 * the lookup must happen here), and the template renders the sourced results
 * with their community attribution. No JavaScript, no browser-side call.
 *
 * The client is fail-soft, so this method always returns a rendered page: a
 * Minoo outage shows a gentle "temporarily unavailable" note rather than a 500.
 */
final class LexiconController
{
    public function __construct(private readonly LexiconClient $client) {}

    /** GET /treaty/language — the page, plus results when ?q= is present. */
    public function page(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        // Only en|oj are valid directions; anything else searches both (blank).
        $dir = (string) $request->query->get('dir', '');
        $dir = in_array($dir, ['en', 'oj'], true) ? $dir : '';

        $lookup = ['performed' => false, 'q' => $query, 'dir' => $dir, 'result' => null];
        if ($query !== '') {
            $lookup['performed'] = true;
            $lookup['result'] = $this->client->lookup($query, dir: $dir);
        }

        return new Response(
            View::render('pages/treaty/language.html.twig', ['lookup' => $lookup]),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
