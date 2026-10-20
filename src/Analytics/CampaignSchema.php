<?php

declare(strict_types=1);

namespace App\Analytics;

use Waaseyaa\Database\DatabaseInterface;

/** Additive operational table, prepared by app:initialize, never by HTTP. */
final class CampaignSchema
{
    public const TABLE = 'analytics_campaign_event';

    public function __construct(private readonly DatabaseInterface $db) {}

    public function ensure(): void
    {
        if ($this->db->schema()->tableExists(self::TABLE)) {
            return;
        }
        $this->db->schema()->createTable(self::TABLE, [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'event_type' => ['type' => 'varchar', 'length' => 20, 'not null' => true],
                'campaign_key' => ['type' => 'varchar', 'length' => 64],
                'event_key' => ['type' => 'varchar', 'length' => 64, 'not null' => true],
                'created_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
            ],
            'primary key' => ['id'],
            'unique keys' => ['campaign_event_key' => ['event_key']],
            'indexes' => ['campaign_created' => ['campaign_key', 'created_at']],
        ]);
    }
}
