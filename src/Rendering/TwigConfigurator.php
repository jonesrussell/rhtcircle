<?php

declare(strict_types=1);

namespace App\Rendering;

use Anokii\Admin\AdminTemplates;
use App\Content\MythEntries;
use Twig\Environment;
use Twig\TwigFunction;

/**
 * Registers the small application-owned Twig surface on the framework-owned
 * environment. The framework supplies template discovery and core extensions;
 * the app supplies only its editorial helpers and shared globals.
 */
final class TwigConfigurator
{
    /** @var \WeakMap<Environment, true>|null */
    private static ?\WeakMap $configured = null;

    public static function configure(Environment $twig, string $projectRoot, PublicAssetVersioner $assets): void
    {
        self::$configured ??= new \WeakMap();
        if (isset(self::$configured[$twig])) {
            return;
        }

        $twig->addFunction(new TwigFunction('current_url', static function (): string {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

            return 'https://rhtcircle.ca' . $path;
        }));
        $twig->addFunction(new TwigFunction('myth', static fn (array $keys): array => MythEntries::select($keys)));
        $twig->addFunction(new TwigFunction('last_updated', static function (string $key) use ($projectRoot): ?string {
            static $map = null;
            if ($map === null) {
                $file = $projectRoot . '/data/freshness.json';
                $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                $map = is_array($decoded) ? $decoded : [];
            }

            return $map[$key] ?? null;
        }));
        $twig->addGlobal('asset_version', $assets->version());

        // Package templates are registered after app templates, so local
        // overrides remain authoritative.
        AdminTemplates::register($twig);
        self::$configured[$twig] = true;
    }
}
