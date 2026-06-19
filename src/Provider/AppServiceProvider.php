<?php

declare(strict_types=1);

namespace App\Provider;

use App\Controller\SiteController;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function routes(WaaseyaaRouter $router, ?\Waaseyaa\Entity\EntityTypeManager $entityTypeManager = null): void
    {
        $controller = new SiteController();

        $pages = [
            'home' => ['/', 'pages/home.html.twig'],

            'treaty-wide' => ['/treaty-wide', 'pages/treaty-wide.html.twig'],
            'treaty-the-treaty' => ['/treaty-wide/the-treaty', 'pages/treaty-wide/the-treaty.html.twig'],
            'treaty-distribution-models' => ['/treaty-wide/distribution-models', 'pages/treaty-wide/distribution-models.html.twig'],

            'standard' => ['/standard', 'pages/standard.html.twig'],
            'records-request' => ['/standard/records-request', 'pages/standard/records-request.html.twig'],

            'about' => ['/about', 'pages/about.html.twig'],
            'get-involved' => ['/get-involved', 'pages/get-involved.html.twig'],

            'communities' => ['/communities', 'pages/communities/index.html.twig'],
            'community-sagamok' => ['/communities/sagamok', 'pages/communities/sagamok.html.twig'],
            'sagamok-how-organized' => ['/communities/sagamok/how-its-organized', 'pages/communities/sagamok/how-its-organized.html.twig'],
            'sagamok-massey' => ['/communities/sagamok/massey', 'pages/communities/sagamok/massey.html.twig'],
            'sagamok-massey-what-youve-heard' => ['/communities/sagamok/massey-what-youve-heard', 'pages/communities/sagamok/massey-what-youve-heard.html.twig'],
            'sagamok-massey-voices' => ['/communities/sagamok/massey-voices', 'pages/communities/sagamok/massey-voices.html.twig'],
            'sagamok-massey-climate' => ['/communities/sagamok/massey-climate', 'pages/communities/sagamok/massey-climate.html.twig'],
        ];

        foreach ($pages as $name => [$path, $template]) {
            $router->addRoute(
                $name,
                RouteBuilder::create($path)
                    ->controller(fn () => $controller->page($template))
                    ->allowAll()
                    ->methods('GET')
                    ->build(),
            );
        }
    }
}
