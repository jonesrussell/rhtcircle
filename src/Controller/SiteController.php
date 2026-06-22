<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\Nations;
use App\Support\View;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Editorial pages for the public site. Thin: render a Twig template (the route
 * table in AppServiceProvider maps each path to a template) and return HTML.
 */
final class SiteController
{
    public function page(string $template): Response
    {
        return $this->html(View::render($template));
    }

    /**
     * The /communities index: the 21 nations grouped by sub-region. Built from
     * the Nations content layer so the index and the per-nation pages stay in
     * sync from one source.
     */
    public function communitiesIndex(): Response
    {
        return $this->html(View::render('pages/communities/index.html.twig', [
            'regions' => Nations::regions(),
            'byRegion' => Nations::byRegion(),
        ]));
    }

    /**
     * A per-nation community page. The unofficial banner, profile facts, and
     * correction link are carried by the shared template; Sagamok keeps its
     * richer hub template but receives the same profile context. Unknown slug
     * renders the 404 page.
     */
    public function community(string $slug): Response
    {
        $nation = Nations::find($slug);
        if ($nation === null) {
            return $this->html(View::render('404.html.twig', ['path' => '/communities/' . $slug]), 404);
        }

        $template = $slug === 'sagamok'
            ? 'pages/communities/sagamok.html.twig'
            : 'pages/communities/nation.html.twig';

        return $this->html(View::render($template, ['nation' => $nation]));
    }

    /** Permanent (301) redirect, for routes that have moved. */
    public function redirect(string $to): Response
    {
        return new RedirectResponse($to, 301);
    }

    private function html(string $body, int $status = 200): Response
    {
        return new Response($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
