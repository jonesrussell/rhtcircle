<?php

declare(strict_types=1);

namespace App\Provider;

use App\Rendering\PublicAssetVersioner;
use App\Rendering\SiteRenderer;
use Twig\Environment;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Application rendering services layered onto Waaseyaa's SSR Twig runtime.
 */
final class RenderingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(PublicAssetVersioner::class, fn (): PublicAssetVersioner => new PublicAssetVersioner(
            $this->projectRoot . '/public',
        ));
        $this->singleton(SiteRenderer::class, fn (): SiteRenderer => new SiteRenderer(
            $this->resolve(Environment::class),
            $this->projectRoot,
            $this->resolve(PublicAssetVersioner::class),
        ));
    }
}
