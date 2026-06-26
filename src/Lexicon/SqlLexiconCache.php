<?php

declare(strict_types=1);

namespace App\Lexicon;

use Waaseyaa\Database\DatabaseInterface;

/**
 * The lexicon cache, backed by the Circle's own SQLite (see LexiconCacheSchema).
 *
 * Reads and writes go through DatabaseInterface (a non-entity operational table).
 * Every method is wrapped so a storage hiccup degrades to a cache miss rather
 * than taking down the page: a failed get() simply means "call Minoo", and a
 * failed put() means "we did not cache this time" — both are safe.
 */
final class SqlLexiconCache implements LexiconCacheInterface
{
    public function __construct(private readonly DatabaseInterface $db) {}

    public function get(string $key): ?string
    {
        try {
            foreach ($this->db->query(
                'SELECT payload, expires_at FROM ' . LexiconCacheSchema::TABLE . ' WHERE cache_key = ? LIMIT 1',
                [$key],
            ) as $row) {
                if ((int) $row['expires_at'] <= time()) {
                    return null;
                }

                return (string) $row['payload'];
            }
        } catch (\Throwable) {
            // Treat any storage error as a cache miss.
        }

        return null;
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        $expiresAt = time() + max(1, $ttlSeconds);
        try {
            // Replace any prior entry for this key so the table does not grow a
            // row per repeated lookup and a refreshed body wins.
            $this->db->query('DELETE FROM ' . LexiconCacheSchema::TABLE . ' WHERE cache_key = ?', [$key]);
            $this->db->query(
                'INSERT INTO ' . LexiconCacheSchema::TABLE
                . ' (cache_key, payload, expires_at, created_at) VALUES (?, ?, ?, ?)',
                [$key, $value, $expiresAt, gmdate('Y-m-d H:i:s')],
            );
        } catch (\Throwable) {
            // Never let a cache write break a lookup.
        }
    }
}
