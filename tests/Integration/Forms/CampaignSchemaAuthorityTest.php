<?php

declare(strict_types=1);

namespace App\Tests\Integration\Forms;

use App\Analytics\CampaignSchema;
use App\Analytics\CampaignSchemaAuthorityRepair;
use App\Content\SiteSchemaInitializer;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

final class CampaignSchemaAuthorityTest extends TestCase
{
    public function testInitializationLeavesTheNextGovernedTransitionUsable(): void
    {
        $db = DBALDatabase::createSqlite();
        $repo = new MigrationRepository($db->getConnection());
        $coordinator = new SchemaMutationCoordinator($db->getConnection(), $repo);
        $coordinator->execute(static fn () => null);
        new SiteSchemaInitializer($db)->initialize();
        self::assertSame($repo->currentLogicalSchemaFingerprint(), $repo->schemaAuthorityManifest()?->schemaFingerprint);
        $coordinator->execute(static fn () => null);
        new SiteSchemaInitializer($db)->initialize();
        self::assertSame($repo->currentLogicalSchemaFingerprint(), $repo->schemaAuthorityManifest()?->schemaFingerprint);
        self::assertFalse(new CampaignSchemaAuthorityRepair($db)->repair()['repaired']);
    }

    public function testRecoveryPreservesEveryEventAndTheSequenceAndThenIsANoop(): void
    {
        $db = $this->legacyAddition();
        $connection = $db->getConnection();
        $before = $connection->fetchAllAssociative('SELECT * FROM analytics_campaign_event ORDER BY id');
        $result = new CampaignSchemaAuthorityRepair($db)->repair();
        self::assertSame(['repaired' => true, 'rows_preserved' => 2], $result);
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM analytics_campaign_event ORDER BY id'));
        self::assertSame(3, (int) $connection->fetchOne("SELECT seq FROM sqlite_sequence WHERE name='analytics_campaign_event'"));
        $repo = new MigrationRepository($connection);
        self::assertSame($repo->currentLogicalSchemaFingerprint(), $repo->schemaAuthorityManifest()?->schemaFingerprint);
        self::assertFalse(new CampaignSchemaAuthorityRepair($db)->repair()['repaired']);
        new SchemaMutationCoordinator($connection, $repo)->execute(static fn () => null);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM analytics_campaign_event'));
    }

    public function testUnrelatedDriftRollsBackTheEntireAttempt(): void
    {
        $db = $this->legacyAddition();
        $db->query('CREATE TABLE unexplained_drift (id INTEGER)');
        $before = $this->snapshot($db);
        try {
            new CampaignSchemaAuthorityRepair($db)->repair();
            self::fail('Unrelated drift must refuse recovery.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('S1-DB109', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot($db));
    }

    public function testUnexpectedCampaignSchemaIsNotAdopted(): void
    {
        $db = $this->legacyAddition();
        $db->query('CREATE INDEX unexpected_campaign_index ON analytics_campaign_event (created_at)');
        $before = $this->snapshot($db);
        try {
            new CampaignSchemaAuthorityRepair($db)->repair();
            self::fail('Unexpected campaign objects must refuse recovery.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('unexpected campaign schema', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot($db));
    }

    private function legacyAddition(): DBALDatabase
    {
        $db = DBALDatabase::createSqlite();
        new SchemaMutationCoordinator($db->getConnection(), new MigrationRepository($db->getConnection()))
            ->execute(static fn () => null);
        new CampaignSchema($db)->ensure();
        foreach ([1, 2, 3] as $id) {
            $db->getConnection()->insert(CampaignSchema::TABLE, [
                'event_type' => 'landing', 'campaign_key' => 'ssr26-release-check',
                'event_key' => hash('sha256', 'test-' . $id), 'created_at' => '2026-10-20 00:00:00',
            ]);
        }
        $db->query('DELETE FROM analytics_campaign_event WHERE id=3');

        return $db;
    }

    /** @return array<string, mixed> */
    private function snapshot(DBALDatabase $db): array
    {
        $connection = $db->getConnection();

        return [
            'schema' => $connection->fetchAllAssociative('SELECT * FROM sqlite_schema ORDER BY name'),
            'authority' => $connection->fetchAllAssociative('SELECT * FROM waaseyaa_schema_authority'),
            'events' => $connection->fetchAllAssociative('SELECT * FROM analytics_campaign_event ORDER BY id'),
            'sequence' => $connection->fetchAllAssociative('SELECT * FROM sqlite_sequence ORDER BY name'),
        ];
    }
}
