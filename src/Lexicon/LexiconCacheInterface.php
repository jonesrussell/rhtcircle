<?php

declare(strict_types=1);

namespace App\Lexicon;

/**
 * A small string key/value cache with per-entry TTL for lexicon lookups.
 *
 * The client stores the raw upstream JSON body keyed by a hash of (q, tag, dir)
 * so an identical lookup re-parses a cached body instead of calling Minoo again.
 * The corpus changes slowly, so a generous TTL keeps us from hammering Minoo.
 */
interface LexiconCacheInterface
{
    /** The cached raw body for a key, or null if absent or expired. */
    public function get(string $key): ?string;

    /** Store a raw body under a key for $ttlSeconds. */
    public function put(string $key, string $value, int $ttlSeconds): void;
}
