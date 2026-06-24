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
        $slug = str_replace('/', '-', (string) preg_replace('/\.html\.twig$/', '', $template));
        $card = \dirname(__DIR__, 2) . '/public/images/og/' . $slug . '.png';

        return is_file($card) ? $base . '/images/og/' . $slug . '.png' : $base . '/images/og-default.png';
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
