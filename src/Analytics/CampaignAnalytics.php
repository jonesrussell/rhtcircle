<?php

declare(strict_types=1);

namespace App\Analytics;

use Waaseyaa\Database\DatabaseInterface;

/** Aggregate campaign events contain no contact content or visitor identifiers. */
final class CampaignAnalytics
{
    public function __construct(private readonly DatabaseInterface $db) {}

    public function landing(mixed $campaign, string $viewId, string $path): void
    {
        $key = CampaignRegistry::known($campaign);
        if ($key === null || CampaignRegistry::all()[$key]['path'] !== $path) {
            return;
        }
        // Repeated delivery of the same browser pageview counts once.
        $eventKey = hash('sha256', 'landing|' . $viewId);
        $this->db->query(
            'INSERT INTO ' . CampaignSchema::TABLE . ' (event_type, campaign_key, event_key, created_at)'
            . ' SELECT ?, ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM ' . CampaignSchema::TABLE . ' WHERE event_key = ?)',
            ['landing', $key, $eventKey, gmdate('Y-m-d H:i:s'), $eventKey],
        );
    }

    /** Called only inside the contact save transaction, never by /api/collect. */
    public function contactSaved(?string $campaign): void
    {
        $this->db->insert(CampaignSchema::TABLE)->values([
            'event_type' => 'contact_saved',
            'campaign_key' => CampaignRegistry::known($campaign),
            'event_key' => bin2hex(random_bytes(32)),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    /** @return array{campaigns:list<array<string,mixed>>, contacts:int, unattributed_contacts:int} */
    public function summary(string $from, string $to): array
    {
        $rows = [];
        foreach (CampaignRegistry::all() as $key => $definition) {
            if (!$definition['test']) {
                $rows[$key] = ['key' => $key, 'label' => $definition['label'], 'landings' => 0, 'contacts' => 0];
            }
        }
        $unattributed = 0;
        $total = 0;
        foreach ($this->db->query(
            'SELECT campaign_key, event_type, COUNT(*) AS total FROM ' . CampaignSchema::TABLE
            . ' WHERE created_at BETWEEN ? AND ? GROUP BY campaign_key, event_type',
            [$from . ' 00:00:00', $to . ' 23:59:59'],
        ) as $row) {
            $key = $row['campaign_key'];
            $count = (int) $row['total'];
            if ($row['event_type'] === 'contact_saved' && ($key === null || isset($rows[$key]))) {
                $total += $count;
                if ($key === null) {
                    $unattributed += $count;
                } else {
                    $rows[$key]['contacts'] += $count;
                }
            } elseif ($row['event_type'] === 'landing' && isset($rows[$key])) {
                $rows[$key]['landings'] += $count;
            }
        }
        return ['campaigns' => array_values($rows), 'contacts' => $total, 'unattributed_contacts' => $unattributed];
    }
}
