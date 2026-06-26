<?php

declare(strict_types=1);

namespace App\Tests\Support\Lexicon;

use App\Lexicon\LexiconCacheInterface;

/**
 * A trivial in-memory {@see LexiconCacheInterface} for tests. Tracks writes so a
 * test can assert that an unavailable result was never cached.
 */
final class ArrayLexiconCache implements LexiconCacheInterface
{
    /** @var array<string, array{value: string, expires: int}> */
    private array $store = [];

    public int $writes = 0;

    public function get(string $key): ?string
    {
        $entry = $this->store[$key] ?? null;
        if ($entry === null || $entry['expires'] <= time()) {
            return null;
        }

        return $entry['value'];
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        ++$this->writes;
        $this->store[$key] = ['value' => $value, 'expires' => time() + max(1, $ttlSeconds)];
    }
}
