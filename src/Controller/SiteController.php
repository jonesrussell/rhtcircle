<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Editorial pages for the public site. Thin: each method renders a Twig
 * template through the App\Support\View layer and returns an HTML response.
 */
final class SiteController
{
    public function home(): Response
    {
        return $this->html('pages/home.html.twig');
    }

    public function treatyWide(): Response
    {
        return $this->html('pages/treaty-wide.html.twig');
    }

    public function standard(): Response
    {
        return $this->html('pages/standard.html.twig');
    }

    public function about(): Response
    {
        return $this->html('pages/about.html.twig');
    }

    public function getInvolved(): Response
    {
        return $this->html('pages/get-involved.html.twig');
    }

    public function communities(): Response
    {
        return $this->html('pages/communities/index.html.twig');
    }

    public function sagamok(): Response
    {
        return $this->html('pages/communities/sagamok.html.twig');
    }

    private function html(string $template, array $context = []): Response
    {
        return new Response(
            View::render($template, $context),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
