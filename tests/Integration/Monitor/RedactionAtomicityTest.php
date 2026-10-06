<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Command\MonitorRedactEventCommand;
use App\Monitor\CollectorState;
use App\Monitor\MonitorEntityTypes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Redaction is all-or-nothing.
 *
 * A partial redaction is the worst outcome this command can produce. The
 * operator is told the content is gone, nobody looks again, and some of it is
 * still there — strictly worse than an outright failure, which at least gets
 * retried. So the whole sequence runs in one transaction.
 *
 * The failure is injected **without any production test hook**: the table that
 * the last step needs is dropped beforehand, so the command deletes the
 * snapshots, then genuinely fails partway through. That is a real mid-sequence
 * fault, not a simulated one, and it exercises exactly the window that would
 * otherwise leave a half-finished redaction committed.
 */
final class RedactionAtomicityTest extends TestCase
{
    private const SOURCE_KEY = 'sagamok_public_site';
    private const PUBLIC_REF = 'sagamok_public_site-0001';
    private const ITEM_KEY = 'notices/water-advisory';
    private const SENTINEL_TITLE = 'ZZREDACTTITLE55 council correspondence';
    private const SENTINEL_BODY = 'ZZREDACTBODY66';

    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?HttpKernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-atomic-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=atomic-test-secret');
        putenv('WAASEYAA_DEV_FALLBACK_ACCOUNT=false');

        $this->runCli('db:init');
    }

    protected function tearDown(): void
    {
        $this->kernel = null;
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    // ------------------------------------------------------------------

    #[Test]
    public function aSuccessfulRedactionRemovesEverythingAndKeepsTheAuditStub(): void
    {
        $eventId = $this->seedFullyRetainedItem();

        // Pre-conditions, asserted so the post-conditions mean something.
        self::assertGreaterThan(0, $this->snapshotCount(), 'snapshots must exist first');
        self::assertNotSame('', $this->stateHash(), 'a content hash must exist first');
        self::assertStringContainsString(self::SENTINEL_TITLE, $this->itemTitle(), 'a content-derived title must exist first');

        $io = new RecordingCommandIo();
        $status = $this->command()->run($io, $eventId, 'personal_information', 9_000);

        self::assertSame(0, $status, $io->output());

        // Everything content-derived is gone.
        self::assertSame(0, $this->snapshotCount(), 'snapshots purged');
        self::assertStringNotContainsString(self::SENTINEL_TITLE, $this->itemTitle(), 'title cleared');
        self::assertSame('', $this->itemUrl(), 'locator cleared');

        $event = $this->eventRow($eventId);
        self::assertSame('', (string) ($event['evidence_url'] ?? 'x'), 'evidence locator cleared');
        self::assertSame('', (string) ($event['notes'] ?? 'x'), 'notes cleared');
        self::assertStringNotContainsString(
            self::SENTINEL_BODY,
            implode('|', array_map(static fn (mixed $v): string => (string) $v, $event)),
            'no content survives on the event row',
        );

        // The minimal audit record survives: the log must not develop holes.
        self::assertSame(9_000, (int) $event['redacted_at'], 'when it was redacted');
        self::assertSame('personal_information', (string) $event['redaction_reason'], 'why');
        self::assertSame('content_changed', (string) $event['event_type'], 'that something happened');
        self::assertSame(500, (int) $event['observed_at'], 'and when it happened');
        self::assertSame(self::PUBLIC_REF, (string) $event['item_public_ref'], 'and to which item');
    }

    #[Test]
    public function aPreSuppressionDatabaseCanBeReadAndRedactedWithoutAnInTransactionMigration(): void
    {
        $eventId = $this->seedFullyRetainedItem();

        // Exact upgrade shape: the collector-state table predates suppression
        // and the additive tombstone table does not exist yet.
        $this->pdo()->exec('DROP TABLE ' . CollectorState::SUPPRESSION_TABLE);
        $columns = $this->pdo()->query('PRAGMA table_info(' . CollectorState::TABLE . ')')->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');
        self::assertNotContains('suppressed_at', $columnNames);
        self::assertNotContains('suppression_reason', $columnNames);

        $state = new CollectorState($this->database());
        self::assertCount(1, $state->allForSource(self::SOURCE_KEY), 'legacy state remains readable');
        self::assertFalse($state->isSuppressed(self::SOURCE_KEY, self::ITEM_KEY));
        $state->clearSuppression(self::SOURCE_KEY, self::ITEM_KEY, 8_000);
        self::assertFalse($this->tableExists(CollectorState::SUPPRESSION_TABLE), 'reads and clear are DDL-free');

        $io = new RecordingCommandIo();
        self::assertSame(0, $this->command()->run($io, $eventId, 'personal_information', 9_000), $io->output());
        self::assertTrue($this->tableExists(CollectorState::SUPPRESSION_TABLE));
        self::assertTrue((new CollectorState($this->database()))->isSuppressed(self::SOURCE_KEY, self::ITEM_KEY));
        self::assertSame(0, $this->snapshotCount());
    }

    #[Test]
    public function aFailurePartwayThroughRollsBackCompletelyAndReportsFailure(): void
    {
        $eventId = $this->seedFullyRetainedItem();

        $snapshotsBefore = $this->snapshotCount();
        $hashBefore = $this->stateHash();
        $titleBefore = $this->itemTitle();
        self::assertGreaterThan(0, $snapshotsBefore);

        // Inject the fault. `monitor_item` is read AFTER the snapshots are
        // deleted, so the command gets partway through and then genuinely
        // fails — the exact window in which a non-transactional implementation
        // would leave the content half-removed.
        $this->pdo()->exec('ALTER TABLE monitor_item RENAME TO monitor_item_hidden');

        $io = new RecordingCommandIo();
        $status = $this->command()->run($io, $eventId, 'personal_information', 9_000);

        // 1. The command must NOT report success.
        self::assertSame(1, $status, 'a failed redaction must exit non-zero');
        $output = $io->output();
        self::assertStringContainsString('NOTHING was changed', $output);
        self::assertStringNotContainsString('Redacted event', $output, 'it must not claim the redaction happened');

        // Restore so the assertions below can read the table.
        $this->pdo()->exec('ALTER TABLE monitor_item_hidden RENAME TO monitor_item');

        // 2. The snapshots are BACK. This is the assertion that matters: the
        //    delete was rolled back, so no content was lost while the operator
        //    was told nothing happened.
        self::assertSame($snapshotsBefore, $this->snapshotCount(), 'snapshot deletes must be rolled back');
        self::assertSame($hashBefore, $this->stateHash(), 'the content hash must be unchanged');
        self::assertSame($titleBefore, $this->itemTitle(), 'the title must be unchanged');

        // 3. The event is still unredacted, so a retry is possible and the
        //    audit log does not claim a redaction that did not occur.
        $event = $this->eventRow($eventId);
        self::assertSame(0, (int) $event['redacted_at'], 'the event must remain unredacted');
        self::assertSame('', (string) ($event['redaction_reason'] ?? ''), 'no reason may be recorded');
        self::assertNotSame('', (string) $event['evidence_url'], 'the evidence locator is untouched');
    }

    #[Test]
    public function theRolledBackRedactionCanBeRetriedSuccessfully(): void
    {
        // Completes the story: a failed redaction leaves the system in a state
        // where the operator's retry works. Without this, "rolled back" could
        // mean "wedged".
        $eventId = $this->seedFullyRetainedItem();

        $this->pdo()->exec('ALTER TABLE monitor_item RENAME TO monitor_item_hidden');
        self::assertSame(1, $this->command()->run(new RecordingCommandIo(), $eventId, 'personal_information', 9_000));
        $this->pdo()->exec('ALTER TABLE monitor_item_hidden RENAME TO monitor_item');

        $io = new RecordingCommandIo();
        self::assertSame(0, $this->command()->run($io, $eventId, 'personal_information', 9_500), $io->output());

        self::assertSame(0, $this->snapshotCount());
        self::assertSame(9_500, (int) $this->eventRow($eventId)['redacted_at']);
    }

    // ------------------------------------------------------------------

    private function seedFullyRetainedItem(): string
    {
        $manager = $this->manager();

        $items = $manager->getRepository(MonitorEntityTypes::ITEM);
        $item = $items->create([
            'source_key' => self::SOURCE_KEY,
            'public_ref' => self::PUBLIC_REF,
            'title' => self::SENTINEL_TITLE,
            'public_url' => 'https://www.sagamokanishnawbek.test/' . self::ITEM_KEY,
            'change_status' => 'changed',
            'first_seen' => 1,
            'last_seen' => 500,
            'changed_at' => 500,
        ]);
        $items->save($item, validate: false);

        $state = new CollectorState($this->database());
        // The side tables are created lazily on first collector run; this
        // fixture writes to them directly, so create them explicitly.
        $state->ensureTable();
        $state->record(self::SOURCE_KEY, self::ITEM_KEY, self::PUBLIC_REF, 'hash-abc', 128, 500);
        $state->appendSnapshot(self::SOURCE_KEY, self::ITEM_KEY, 'hash-abc', self::SENTINEL_BODY, 500);
        $state->appendSnapshot(self::SOURCE_KEY, self::ITEM_KEY, 'hash-def', self::SENTINEL_BODY . '2', 600);

        $events = $manager->getRepository(MonitorEntityTypes::EVENT);
        $event = $events->create([
            'source_key' => self::SOURCE_KEY,
            'item_public_ref' => self::PUBLIC_REF,
            'event_type' => 'content_changed',
            'observed_at' => 500,
            'evidence_kind' => 'direct_fetch',
            'evidence_url' => 'https://www.sagamokanishnawbek.test/' . self::ITEM_KEY,
            'evidence_captured_at' => 500,
            'notes' => self::SENTINEL_BODY,
            'redacted_at' => 0,
        ]);
        $events->save($event, validate: false);

        return (string) $event->id();
    }

    private function command(): MonitorRedactEventCommand
    {
        return new MonitorRedactEventCommand(
            $this->manager(),
            new CollectorState($this->database()),
            $this->database(),
        );
    }

    private function snapshotCount(): int
    {
        return (int) $this->pdo()
            ->query('SELECT COUNT(*) FROM ' . CollectorState::SNAPSHOT_TABLE)
            ->fetchColumn();
    }

    private function stateHash(): string
    {
        $v = $this->pdo()->query('SELECT content_hash FROM ' . CollectorState::TABLE . ' LIMIT 1')->fetchColumn();

        return $v === false ? '' : (string) $v;
    }

    private function itemTitle(): string
    {
        $v = $this->pdo()->query("SELECT title FROM monitor_item WHERE public_ref = '" . self::PUBLIC_REF . "'")->fetchColumn();

        return $v === false ? '' : (string) $v;
    }

    private function itemUrl(): string
    {
        $v = $this->pdo()->query("SELECT public_url FROM monitor_item WHERE public_ref = '" . self::PUBLIC_REF . "'")->fetchColumn();

        return $v === false ? '' : (string) $v;
    }

    /** @return array<string, mixed> */
    private function eventRow(string $id): array
    {
        $row = $this->pdo()->query('SELECT * FROM monitor_event WHERE id = ' . (int) $id)->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'the event row must survive redaction as a stub');

        return $row;
    }

    private function pdo(): \PDO
    {
        return new \PDO('sqlite:' . $this->databasePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function tableExists(string $table): bool
    {
        $quoted = $this->pdo()->quote($table);

        return (int) $this->pdo()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=" . $quoted)->fetchColumn() === 1;
    }

    private function manager(): EntityTypeManager
    {
        return $this->kernel()->getEntityTypeManager();
    }

    private function database(): DatabaseInterface
    {
        return $this->kernel()->getDatabase();
    }

    private function kernel(): HttpKernel
    {
        if ($this->kernel === null) {
            $this->kernel = new HttpKernel($this->projectRoot);
            $this->kernel->bootForCli();
        }

        return $this->kernel;
    }

    private function runCli(string $command): void
    {
        $process = proc_open(
            [PHP_BINARY, $this->projectRoot . '/vendor/bin/waaseyaa', $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectRoot,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), trim($stdout . "\n" . $stderr));
    }
}
