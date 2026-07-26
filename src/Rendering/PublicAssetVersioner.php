<?php

declare(strict_types=1);

namespace App\Rendering;

/**
 * One cache key for the public presentation layer. A changed stylesheet or
 * script changes every asset URL in the shared Twig layout.
 */
final class PublicAssetVersioner
{
    private const array FILES = [
        'css/site.css',
        'js/rht-analytics.js',
        'js/rht-anokii-chat.js',
    ];

    private ?string $version = null;

    public function __construct(private readonly string $publicRoot) {}

    public function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $hash = hash_init('sha256');
        foreach (self::FILES as $relativePath) {
            $path = rtrim($this->publicRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!is_file($path) || !is_readable($path)) {
                throw new \RuntimeException("Required public asset is unavailable: $relativePath");
            }
            hash_update($hash, $relativePath . "\0");
            if (!hash_update_file($hash, $path)) {
                throw new \RuntimeException("Could not hash public asset: $relativePath");
            }
        }

        return $this->version = substr(hash_final($hash), 0, 12);
    }
}
