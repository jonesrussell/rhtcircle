<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Command\MonitorPublicCommand;
use App\Command\MonitorRedactEventCommand;
use App\Monitor\CollectorState;
use App\Monitor\FetchResult;
use App\Monitor\FixturePageFetcher;
use App\Monitor\MonitorEntityTypes;
use App\Provider\SagamokMonitorServiceProvider;
use App\Schedule\SagamokMonitorSchedule;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Spec §4.1 and §3.7: the scheduled entry point ships **disabled**, the
 * collector command is fixture-injectable and truly write-free in `--dry-run`,
 * and redaction fails closed while retaining a stub.
 */
final class ScheduleAndCommandsTest extends TestCase
{
    private const ORIGIN = 'https://www.sagamokanishnawbek.test/';
    private const PAGE = self::ORIGIN . 'notices/water-advisory';

    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?HttpKernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-sched-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=sched-test-secret');
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
    // The schedule is declared and disabled
    // ------------------------------------------------------------------

    public function testTheScheduleEntryIsDeclared(): void
    {
        $schedule = new \Waaseyaa\Scheduler\Schedule();
        $tasks = new SagamokMonitorSchedule()->register($schedule);

        self::assertArrayHasKey(SagamokMonitorSchedule::TASK_ID, $tasks);
        self::assertSame('sagamok:monitor-public', $tasks[SagamokMonitorSchedule::TASK_ID]->command);
        self::assertSame('0 * * * *', $tasks[SagamokMonitorSchedule::TASK_ID]->expression);
    }

    public function testTheScheduleEntryShipsDisabled(): void
    {
        // The load-bearing assertion. Enabling the monitor means this app
        // starts making automated requests to another Nation's website on a
        // timer; that must never become true as a side effect of an edit.
        $config = require $this->projectRoot . '/config/waaseyaa.php';

        self::assertContains(
            SagamokMonitorSchedule::class,
            $config['schedule']['disabled_entries'] ?? [],
            'the monitor schedule must remain disabled by default',
        );
    }

    public function testTheBootedKernelSchedulesNothingForTheMonitor(): void
    {
        // Proven against the real kernel, not just the config array: the
        // disabled entry must actually result in no live task.
        $schedule = $this->kernel()->getSchedule();

        foreach ($schedule->tasks() as $task) {
            self::assertNotSame(
                SagamokMonitorSchedule::TASK_ID,
                $task->name,
                'a disabled entry must not produce a live scheduled task',
            );
        }
    }

    // ------------------------------------------------------------------
    // The collector command
    // ------------------------------------------------------------------

    public function testDryRunWritesNothingIncludingNoSideTable(): void
    {
        $this->seedSource();
        $fetcher = FixturePageFetcher::withPages([self::PAGE => '<html><head><title>A</title></head><body>x</body></html>']);

        $io = $this->io();
        $status = new MonitorPublicCommand($this->manager(), $this->kernel()->getDatabase(), $fetcher)
            ->run($io, [self::PAGE], dryRun: true, now: 1_000);

        self::assertSame(0, $status);
        self::assertStringContainsString('DRY RUN', $io->output());

        self::assertSame(0, $this->countRowsOf(MonitorEntityTypes::ITEM), 'no entity written');
        self::assertSame(0, $this->countRowsOf(MonitorEntityTypes::EVENT), 'no event written');

        // The DDL assertion: a dry run must not even create the side table.
        // Otherwise --dry-run is write-free "except for schema", which is not
        // write-free, and the first dry run on a fresh host would silently
        // change the database.
        self::assertFalse($this->tableExists(CollectorState::TABLE), 'no side table created');
    }

    public function testALiveRunReportsClearTotals(): void
    {
        $this->seedSource();
        $fetcher = FixturePageFetcher::withPages([self::PAGE => '<html><head><title>A</title></head><body>x</body></html>']);
        $fetcher->set(self::ORIGIN . 'gated', FetchResult::success(401, self::ORIGIN . 'gated', ''));
        $fetcher->set(self::ORIGIN . 'quiet', FetchResult::success(
            200,
            self::ORIGIN . 'quiet',
            '<html><head><meta name="robots" content="noindex"></head><body>y</body></html>',
        ));
        $fetcher->set(self::ORIGIN . 'down', FetchResult::failure(self::ORIGIN . 'down', 'timeout'));

        $io = $this->io();
        new MonitorPublicCommand($this->manager(), $this->kernel()->getDatabase(), $fetcher)
            ->run($io, [self::PAGE, self::ORIGIN . 'gated', self::ORIGIN . 'quiet', self::ORIGIN . 'down'], false, 1_000);

        $output = $io->output();
        self::assertStringContainsString('new 1', $output);
        self::assertStringContainsString('gated 1', $output);
        self::assertStringContainsString('not retained 1', $output);
        self::assertStringContainsString('failed 1', $output);

        // The wording must not imply noindex restricted access.
        self::assertStringContainsString('they remain publicly reachable', $output);
    }

    public function testTheCommandRefusesWhenNoSourceIsRegistered(): void
    {
        $io = $this->io();
        $status = new MonitorPublicCommand($this->manager(), $this->kernel()->getDatabase(), new FixturePageFetcher())
            ->run($io, [self::PAGE], false, 1_000);

        self::assertSame(1, $status, 'fail closed rather than inventing a source');
    }

    public function testAllThreeCommandsAreDiscoverableFromTheProvider(): void
    {
        $names = [];
        foreach (new SagamokMonitorServiceProvider()->consoleCommands() as $command) {
            self::assertInstanceOf(HandlerCommand::class, $command);
            $names[] = $command->name;
        }

        self::assertSame([
            'sagamok:monitor-public',
            'sagamok:monitor-triage',
            'sagamok:monitor-redact-event',
        ], $names);
    }

    // ------------------------------------------------------------------
    // Redaction — fails closed, retains a stub
    // ------------------------------------------------------------------

    public function testRedactionRetainsAStubAndClearsTheLocator(): void
    {
        $repository = $this->manager()->getRepository(MonitorEntityTypes::EVENT);
        $event = $repository->create([
            'source_key' => 'sagamok_public_site',
            'item_public_ref' => 'p-1',
            'event_type' => 'content_changed',
            'observed_at' => 500,
            'evidence_kind' => 'direct_fetch',
            'evidence_url' => self::PAGE,
            'redacted_at' => 0,
        ]);
        $repository->save($event, validate: false);
        $id = (string) $event->id();

        $io = $this->io();
        $status = new MonitorRedactEventCommand($this->manager())->run($io, $id, 'member_request', 9_000);

        self::assertSame(0, $status);

        $stub = $repository->find($id);
        self::assertNotNull($stub, 'the row is RETAINED — the log must not develop holes');
        self::assertSame(9_000, (int) $stub->get('redacted_at'));
        self::assertSame('member_request', (string) $stub->get('redaction_reason'));
        self::assertSame('', (string) $stub->get('evidence_url'), 'the locator is cleared');
        self::assertSame('content_changed', (string) $stub->get('event_type'), 'the fact that something changed is preserved');
        self::assertSame(500, (int) $stub->get('observed_at'), 'and when');
    }

    public function testRedactionFailsClosedOnAnUnknownId(): void
    {
        $io = $this->io();
        self::assertSame(1, new MonitorRedactEventCommand($this->manager())->run($io, '999999', 'member_request', 9_000));
    }

    public function testRedactionFailsClosedOnAnUnknownReason(): void
    {
        // Free-text reasons are rejected: the reason is rendered publicly
        // beside the stub, so it would otherwise be an unreviewed publication
        // surface.
        $io = $this->io();
        self::assertSame(1, new MonitorRedactEventCommand($this->manager())->run($io, '1', 'because I said so', 9_000));
    }

    public function testRedactionIsNotRepeatable(): void
    {
        $repository = $this->manager()->getRepository(MonitorEntityTypes::EVENT);
        $event = $repository->create([
            'source_key' => 'sagamok_public_site',
            'item_public_ref' => 'p-1',
            'event_type' => 'appeared',
            'observed_at' => 500,
            'redacted_at' => 0,
        ]);
        $repository->save($event, validate: false);
        $id = (string) $event->id();

        $command = new MonitorRedactEventCommand($this->manager());
        self::assertSame(0, $command->run($this->io(), $id, 'member_request', 9_000));
        self::assertSame(1, $command->run($this->io(), $id, 'inaccurate', 9_500), 'a second redaction must fail closed');

        // And the original reason and timestamp survive the refused attempt.
        $stub = $repository->find($id);
        self::assertSame('member_request', (string) $stub?->get('redaction_reason'));
        self::assertSame(9_000, (int) $stub?->get('redacted_at'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function seedSource(): void
    {
        $repository = $this->manager()->getRepository(MonitorEntityTypes::SOURCE);
        $source = $repository->create([
            'key' => 'sagamok_public_site',
            'label' => 'Sagamok public website',
            'origin_url' => self::ORIGIN,
            'enabled' => true,
            'health' => 'ok',
        ]);
        $repository->save($source, validate: false);
    }

    private function countRowsOf(string $type): int
    {
        return count(array_values(iterator_to_array($this->manager()->getRepository($type)->findBy([]), false)));
    }

    private function tableExists(string $table): bool
    {
        $pdo = new \PDO('sqlite:' . $this->databasePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $table . "'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        return $rows !== [];
    }

    private function io(): RecordingCommandIo
    {
        return new RecordingCommandIo();
    }

    private function manager(): EntityTypeManager
    {
        return $this->kernel()->getEntityTypeManager();
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
