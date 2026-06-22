<?php

declare(strict_types=1);

namespace App\Analytics;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Creates the append-only analytics event table on demand.
 *
 * The framework has no migration CLI, so the table is ensured at boot, guarded
 * by tableExists() (the same pattern as PetitionSchema). This is a non-entity,
 * audit-log-style table and therefore uses DatabaseInterface directly (per
 * framework convention).
 *
 * SOVEREIGNTY NOTE (OCAP): every row lives only in the hub's own SQLite database
 * on hub-controlled hardware. There is no third party and no ad-tech. A visitor
 * is identified only by a daily-rotating salted hash; raw IP and user-agent are
 * never stored.
 */
final class AnalyticsSchema
{
    public const TABLE = 'analytics_event';

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
                'event_type' => ['type' => 'varchar', 'length' => 20, 'not null' => true],
                'path' => ['type' => 'varchar', 'length' => 255],
                'referrer_host' => ['type' => 'varchar', 'length' => 255],
                'view_id' => ['type' => 'varchar', 'length' => 64],
                'visitor_hash' => ['type' => 'varchar', 'length' => 64],
                'device' => ['type' => 'varchar', 'length' => 20],
                'scroll_pct' => ['type' => 'int'],
                'dwell_ms' => ['type' => 'int'],
                'created_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_ae_created' => ['created_at'],
                'idx_ae_type' => ['event_type'],
                'idx_ae_view' => ['view_id'],
                'idx_ae_visitor' => ['visitor_hash', 'created_at'],
            ],
        ]);
    }
}
