<?php

declare(strict_types=1);

namespace App\Rendering;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Single rendering boundary for public and admin Twig pages.
 *
 * Waaseyaa owns the Twig Environment and template-loader chain. This class
 * adds the RHT Circle context shared by every page and returns typed responses.
 */
final class SiteRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly string $projectRoot,
        private readonly PublicAssetVersioner $assets,
    ) {
        TwigConfigurator::configure($this->twig, $this->projectRoot, $this->assets);
    }

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context = []): string
    {
        $context['og_image_url'] ??= $this->ogImageUrl($template);
        $context['asset_version'] ??= $this->assets->version();

        return $this->twig->render($template, $context);
    }

    /** @param array<string, mixed> $context */
    public function html(string $template, array $context = [], int $status = 200): Response
    {
        return new Response(
            $this->render($template, $context),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function ogImageUrl(string $template): string
    {
        $slug = str_replace('/', '-', (string) preg_replace('/\.html\.twig$/', '', $template));
        $card = $this->projectRoot . '/public/images/og/' . $slug . '.png';
        if (is_file($card)) {
            return 'https://rhtcircle.ca/images/og/' . $slug . '.png?v=' . $this->fileVersion($card);
        }

        $default = $this->projectRoot . '/public/images/og-default.png';

        return 'https://rhtcircle.ca/images/og-default.png'
            . (is_file($default) ? '?v=' . $this->fileVersion($default) : '');
    }

    private function fileVersion(string $file): string
    {
        return substr((string) hash_file('crc32b', $file), 0, 8);
    }
}
