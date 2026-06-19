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
            'home' => ['/', fn () => $controller->home()],
            'treaty-wide' => ['/treaty-wide', fn () => $controller->treatyWide()],
            'standard' => ['/standard', fn () => $controller->standard()],
            'about' => ['/about', fn () => $controller->about()],
            'get-involved' => ['/get-involved', fn () => $controller->getInvolved()],
            'communities' => ['/communities', fn () => $controller->communities()],
            'community-sagamok' => ['/communities/sagamok', fn () => $controller->sagamok()],
        ];

        foreach ($pages as $name => [$path, $action]) {
            $router->addRoute(
                $name,
                RouteBuilder::create($path)
                    ->controller($action)
                    ->allowAll()
                    ->methods('GET')
                    ->build(),
            );
        }
    }
}
