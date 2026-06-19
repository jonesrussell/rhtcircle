<?php

declare(strict_types=1);

namespace App\Support;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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
        $cacheDir = $root . '/storage/cache/twig';
        $cache = is_dir($cacheDir) || @mkdir($cacheDir, 0775, true) ? $cacheDir : false;

        self::$twig = new Environment(
            new FilesystemLoader($root . '/templates'),
            [
                'cache' => getenv('APP_ENV') === 'production' ? $cache : false,
                'autoescape' => 'html',
                'strict_variables' => false,
            ],
        );

        return self::$twig;
    }
}
