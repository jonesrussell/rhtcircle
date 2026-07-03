<?php

declare(strict_types=1);

namespace App\Poll;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Creates the poll tables on demand (same on-boot, tableExists()-guarded
 * pattern as PetitionSchema; the framework has no migration CLI).
 *
 * SOVEREIGNTY NOTE (OCAP): a vote is never a row. Casting a vote only
 * increments an aggregate counter on poll_option, so there is no per-voter
 * record to store, export, or leak: no name, no email, no IP, not even
 * hashed, next to a vote. The only other table, poll_rate_limit, exists
 * purely to soft-throttle rapid repeat submissions from one network; it holds
 * a salted one-way IP hash and a timestamp, and is never joined to poll or
 * poll_option, so throttling can never be traced back to how anyone voted.
 */
final class PollSchema
{
    public const TABLE_POLL = 'poll';
    public const TABLE_OPTION = 'poll_option';
    public const TABLE_RATE_LIMIT = 'poll_rate_limit';

    public function __construct(private readonly DatabaseInterface $db) {}

    public function ensure(): void
    {
        $this->ensurePollTable();
        $this->ensureOptionTable();
        $this->ensureRateLimitTable();
    }

    private function ensurePollTable(): void
    {
        $schema = $this->db->schema();
        if ($schema->tableExists(self::TABLE_POLL)) {
            return;
        }

        $schema->createTable(self::TABLE_POLL, [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'slug' => ['type' => 'varchar', 'length' => 100, 'not null' => true],
                'question' => ['type' => 'varchar', 'length' => 500, 'not null' => true],
                'active' => ['type' => 'int', 'not null' => true, 'default' => 1],
                'created_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_poll_slug' => ['slug'],
            ],
        ]);
    }

    private function ensureOptionTable(): void
    {
        $schema = $this->db->schema();
        if ($schema->tableExists(self::TABLE_OPTION)) {
            return;
        }

        $schema->createTable(self::TABLE_OPTION, [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'poll_id' => ['type' => 'int', 'not null' => true],
                'position' => ['type' => 'int', 'not null' => true, 'default' => 0],
                'label' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                // The vote itself: an aggregate count only, incremented in
                // place. No per-vote row ever exists, so there is nothing to
                // de-anonymize.
                'votes' => ['type' => 'int', 'not null' => true, 'default' => 0],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_poll_option_poll' => ['poll_id', 'position'],
            ],
        ]);
    }

    private function ensureRateLimitTable(): void
    {
        $schema = $this->db->schema();
        if ($schema->tableExists(self::TABLE_RATE_LIMIT)) {
            return;
        }

        $schema->createTable(self::TABLE_RATE_LIMIT, [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                // Salted one-way hash, kept ONLY for throttling. Cannot be
                // reversed to an IP and is never linked to any poll or vote.
                'ip_hash' => ['type' => 'varchar', 'length' => 64, 'not null' => true],
                'created_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_poll_rate_ip' => ['ip_hash', 'created_at'],
            ],
        ]);
    }
}
