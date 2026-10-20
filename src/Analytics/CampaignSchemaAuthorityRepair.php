<?php

declare(strict_types=1);

namespace App\Analytics;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

/**
 * One-time recovery for the uncoordinated October 20 campaign-table addition.
 *
 * Restores the exact recorded pre-state inside a writer transaction, then
 * reapplies the app table and every row through the framework coordinator.
 * It never clears or overwrites a manifest to make unexplained drift pass.
 */
final readonly class CampaignSchemaAuthorityRepair
{
    public function __construct(private DBALDatabase $database) {}

    /** @return array{repaired:bool, rows_preserved:int} */
    public function repair(): array
    {
        $connection = $this->database->getConnection();
        $repository = new MigrationRepository($connection);

        return $this->database->transactional(function () use ($connection, $repository): array {
            $repository->acquireSchemaAuthority();
            $manifest = $repository->schemaAuthorityManifest();
            if ($manifest?->schemaFingerprint === null || $manifest->ledgerFingerprint === null) {
                throw new \RuntimeException('Campaign recovery requires an existing fingerprinted authority.');
            }
            if ($manifest->ledgerFingerprint !== $repository->currentLedgerFingerprint()) {
                throw new \RuntimeException('Campaign recovery refuses migration ledger drift.');
            }
            $beforeSchema = $repository->currentLogicalSchemaFingerprint();
            if ($beforeSchema === $manifest->schemaFingerprint) {
                return ['repaired' => false, 'rows_preserved' => 0];
            }

            // Generate the expected table with the same app schema definition.
            // Extra columns, indexes or triggers must refuse recovery.
            $expected = DBALDatabase::createSqlite();
            new CampaignSchema($expected)->ensure();
            $sql = "SELECT type, name, tbl_name, sql FROM sqlite_schema WHERE tbl_name = ? AND name NOT LIKE 'sqlite_%' ORDER BY type, name";
            $actualObjects = $connection->fetchAllAssociative($sql, [CampaignSchema::TABLE]);
            if ($actualObjects !== $expected->getConnection()->fetchAllAssociative($sql, [CampaignSchema::TABLE])) {
                throw new \RuntimeException('Campaign recovery refuses an unexpected campaign schema.');
            }
            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM analytics_campaign_event');
            if ($count > 10000) {
                throw new \RuntimeException('Campaign recovery exceeds its reviewed row bound.');
            }
            $rows = $connection->fetchAllAssociative('SELECT * FROM analytics_campaign_event ORDER BY id');
            $sequence = (int) $connection->fetchOne("SELECT seq FROM sqlite_sequence WHERE name = 'analytics_campaign_event'");

            // This temporary removal is invisible outside the transaction.
            // Any failure, including unrelated schema drift, restores it.
            $this->database->schema()->dropTable(CampaignSchema::TABLE);
            new SchemaMutationCoordinator($connection, $repository)->execute(function () use ($connection, $rows, $sequence): void {
                new CampaignSchema($this->database)->ensure();
                foreach ($rows as $row) {
                    $connection->insert(CampaignSchema::TABLE, $row);
                }
                $connection->executeStatement(
                    "INSERT INTO sqlite_sequence (name, seq) SELECT 'analytics_campaign_event', ? WHERE NOT EXISTS (SELECT 1 FROM sqlite_sequence WHERE name = 'analytics_campaign_event')",
                    [$sequence],
                );
                $connection->executeStatement("UPDATE sqlite_sequence SET seq = ? WHERE name = 'analytics_campaign_event'", [$sequence]);
            });
            if ($beforeSchema !== $repository->currentLogicalSchemaFingerprint()
                || $rows !== $connection->fetchAllAssociative('SELECT * FROM analytics_campaign_event ORDER BY id')
                || $sequence !== (int) $connection->fetchOne("SELECT seq FROM sqlite_sequence WHERE name = 'analytics_campaign_event'")) {
                throw new \RuntimeException('Campaign recovery round-trip verification failed.');
            }
            $repository->assertSchemaAuthorityPreState();

            return ['repaired' => true, 'rows_preserved' => $count];
        });
    }
}
