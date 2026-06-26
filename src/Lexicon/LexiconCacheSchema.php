<?php

declare(strict_types=1);

namespace App\Lexicon;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Creates the lexicon lookup cache table on demand.
 *
 * Same pattern as {@see \App\Petition\PetitionSchema}: the framework has no
 * migration CLI, so the table is ensured at boot, guarded by tableExists(). It
 * is a first-party operational cache table (no personal data, only public
 * dictionary lookups and their results), so it uses DatabaseInterface directly.
 */
final class LexiconCacheSchema
{
    public const string TABLE = 'lexicon_cache';

    public function __construct(private readonly DatabaseInterface $db) {}

    public function ensure(): void
    {
        $schema = $this->db->schema();
        if ($schema->tableExists(self::TABLE)) {
            return;
        }

        $schema->createTable(self::TABLE, [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                // sha256 of the normalized (q|tag|dir) lookup key.
                'cache_key' => ['type' => 'varchar', 'length' => 64, 'not null' => true],
                // The raw upstream JSON body, re-parsed on a cache hit.
                'payload' => ['type' => 'text', 'not null' => true],
                // Unix timestamp after which the entry is stale.
                'expires_at' => ['type' => 'int', 'not null' => true],
                'created_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_lex_key' => ['cache_key'],
                'idx_lex_expires' => ['expires_at'],
            ],
        ]);
    }
}
