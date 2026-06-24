<?php

declare(strict_types=1);

namespace App\Support;

use Anokii\Admin\AdminTemplates;
use App\Content\MythEntries;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Minimal Twig view layer for the marketing site. The framework's SSR package
 * is entity/component oriented; for hand-authored editorial pages a plain Twig
 * environment over templates/ (the same shape the framework's own SSR tests use)
 * is simpler and keeps full template inheritance.
 */
final class View
{
    private static ?Environment $twig = null;

    public static function render(string $template, array $context = []): string
    {
        // Per-page Open Graph card, resolved by convention unless the caller set
        // one explicitly. base.html.twig reads og_image_url for og:image.
        if (!array_key_exists('og_image_url', $context)) {
            $context['og_image_url'] = self::ogImageUrl($template);
        }

        return self::twig()->render($template, $context);
    }

    /**
     * Per-page Open Graph card URL by convention: public/images/og/<slug>.png,
     * where <slug> is the template path with '.html.twig' stripped and '/'
     * replaced by '-' (the exact slug scripts/generate-og.js writes). Falls back
     * to the site default when no card has been rendered for the page yet, so a
     * missing card degrades to a generic preview rather than a broken image.
     */
    private static function ogImageUrl(string $template): string
    {
        $base = 'https://rhtcircle.ca';
        $root = \dirname(__DIR__, 2);
        $slug = str_replace('/', '-', (string) preg_replace('/\.html\.twig$/', '', $template));
        $card = $root . '/public/images/og/' . $slug . '.png';
        if (is_file($card)) {
            return $base . '/images/og/' . $slug . '.png?v=' . self::cardVersion($card);
        }
        $default = $root . '/public/images/og-default.png';

        return $base . '/images/og-default.png' . (is_file($default) ? '?v=' . self::cardVersion($default) : '');
    }

    /**
     * Short content hash appended to a card URL as ?v=, so a regenerated card
     * gets a fresh URL (busting Cloudflare's edge cache and forcing social
     * scrapers to re-fetch), while an unchanged card keeps a stable, cacheable
     * URL. The page HTML itself is uncached, so this is recomputed per render.
     */
    private static function cardVersion(string $file): string
    {
        return substr((string) hash_file('crc32b', $file), 0, 8);
    }

    private static function twig(): Environment
    {
        if (self::$twig instanceof Environment) {
            return self::$twig;
        }

        $root = \dirname(__DIR__, 2);
        // Ephemeral, per-container cache (NOT the persistent storage volume): a
        // fresh container on each deploy starts with an empty cache, so a changed
        // template always recompiles. A cache on the persistent volume, combined
        // with the image's opcache validate_timestamps=0, would otherwise serve a
        // stale compiled template after a deploy.
        $cacheDir = $root . '/var/twig-cache';
        $cache = is_dir($cacheDir) || @mkdir($cacheDir, 0775, true) ? $cacheDir : false;

        self::$twig = new Environment(
            new FilesystemLoader($root . '/templates'),
            [
                'cache' => getenv('APP_ENV') === 'production' ? $cache : false,
                'autoescape' => 'html',
                'strict_variables' => false,
            ],
        );

        // Myth-versus-record entries, so the cross-cutting component is available
        // to any template (and to app:ingest's render) without per-route context.
        // myth(['key', ...]) selects entries by key; myth_all() returns them all.
        self::$twig->addFunction(new TwigFunction('myth', static fn (array $keys): array => MythEntries::select($keys)));
        self::$twig->addFunction(new TwigFunction('myth_all', static fn (): array => MythEntries::ordered()));

        // Make the shared Anokii admin shell + templates (anokii/_shell.html.twig,
        // anokii/admin/*.html.twig) resolvable. Appended, so app templates win.
        AdminTemplates::register(self::$twig);

        return self::$twig;
    }
}
