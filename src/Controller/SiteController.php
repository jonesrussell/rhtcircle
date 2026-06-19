<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Editorial pages for the public site. Thin: render a Twig template (the route
 * table in AppServiceProvider maps each path to a template) and return HTML.
 */
final class SiteController
{
    public function page(string $template): Response
    {
        return new Response(
            View::render($template),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
