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
        return self::twig()->render($template, $context);
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
