<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Entity\MonitorSource;
use App\Monitor\CollectorState;
use App\Monitor\FetchResult;
use App\Monitor\FixturePageFetcher;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\PublicSiteCollector;
use App\Monitor\SagamokMonitorRepository;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Spec §9.5 (source health) and the reappearance half of §9.4.
 *
 * Health is not decoration. A monitoring dashboard that silently stops checking
 * is worse than no dashboard, because it invites members to read a stale page as
 * current — so the failure, recovery and stalled cases each have a test.
 */
final class SourceHealthTest extends TestCase
{
    private const SOURCE_KEY = 'sagamok_public_site';
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
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-health-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=health-test-secret');
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
    // Health transitions
    // ------------------------------------------------------------------

    public function testAFetchFailureDegradesHealth(): void
    {
        $fetcher = new FixturePageFetcher()->set(self::PAGE, FetchResult::failure(self::PAGE, 'connection timed out'));

        $run = $this->collector($fetcher)->run($this->source(), [self::PAGE], 1_000);

        self::assertSame('failing', $run['health'], 'every fetch failed and nothing was observed');
        self::assertSame('failing', (string) $this->source()->get('health'));
        self::assertSame(1, (int) $this->source()->get('consecutive_failures'));
    }

    public function testAPartialFailureIsDegradedRatherThanFailing(): void
    {
        // A site that answers some pages is not down; reporting it as failing
        // would train operators to ignore the badge.
        $fetcher = FixturePageFetcher::withPages([self::PAGE => $this->page('A', 'body')]);
        $fetcher->set(self::ORIGIN . 'notices/other', FetchResult::failure(self::ORIGIN . 'notices/other', 'timeout'));

        $run = $this->collector($fetcher)->run($this->source(), [self::PAGE, self::ORIGIN . 'notices/other'], 1_000);

        self::assertSame('degraded', $run['health']);
    }

    public function testRecoveryRestoresHealthAndRecordsTheTransitionOnce(): void
    {
        $fetcher = new FixturePageFetcher()->set(self::PAGE, FetchResult::failure(self::PAGE, 'timeout'));
        $this->collector($fetcher)->run($this->source(), [self::PAGE], 1_000);
        self::assertSame('failing', (string) $this->source()->get('health'));

        $fetcher->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('A', 'body')));
        $recovery = $this->collector($fetcher)->run($this->source(), [self::PAGE], 2_000);

        self::assertSame('ok', $recovery['health']);
        self::assertSame('ok', (string) $this->source()->get('health'));
        self::assertSame(0, (int) $this->source()->get('consecutive_failures'), 'the failure streak resets on recovery');
        self::assertSame(2_000, (int) $this->source()->get('last_success'));

        // A second healthy run is not a second recovery: health stays ok and
        // the streak stays reset, with nothing re-announced.
        $steady = $this->collector($fetcher)->run($this->source(), [self::PAGE], 3_000);
        self::assertSame('ok', $steady['health']);
        self::assertSame([], $steady['events'], 'a steady healthy run announces nothing');
    }

    public function testConsecutiveFailuresAccumulate(): void
    {
        $fetcher = new FixturePageFetcher()->set(self::PAGE, FetchResult::failure(self::PAGE, 'timeout'));

        $this->collector($fetcher)->run($this->source(), [self::PAGE], 1_000);
        $this->collector($fetcher)->run($this->source(), [self::PAGE], 2_000);
        $this->collector($fetcher)->run($this->source(), [self::PAGE], 3_000);

        self::assertSame(3, (int) $this->source()->get('consecutive_failures'));
    }

    public function testNoindexDoesNotDegradeHealth(): void
    {
        $fetcher = new FixturePageFetcher()->set(self::PAGE, FetchResult::success(
            200,
            self::PAGE,
            '<html><head><meta name="robots" content="noindex"></head><body>Public but unretained</body></html>',
        ));

        $run = $this->collector($fetcher)->run($this->source(), [self::PAGE], 1_000);

        self::assertSame('ok', $run['health'], 'the site answered normally; honouring noindex is not a fault');
        self::assertSame('ok', (string) $this->source()->get('health'));
        self::assertSame(0, (int) $this->source()->get('consecutive_failures'));
    }

    public function testDryRunNeverMutatesHealth(): void
    {
        $fetcher = new FixturePageFetcher()->set(self::PAGE, FetchResult::failure(self::PAGE, 'timeout'));

        $run = $this->collector($fetcher)->run($this->source(), [self::PAGE], 1_000, dryRun: true);

        self::assertSame('failing', $run['health'], 'the report still states what would happen');
        self::assertSame('ok', (string) $this->source()->get('health'), 'but the stored health is untouched');
        self::assertSame(0, (int) $this->source()->get('last_check_completed'));
        self::assertSame(0, (int) $this->source()->get('consecutive_failures'));
    }

    public function testStalledIsDerivedFromTheLastCompletedCheck(): void
    {
        $repository = new SagamokMonitorRepository($this->manager());
        $source = $this->source();

        $fetcher = FixturePageFetcher::withPages([self::PAGE => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($source, [self::PAGE], 1_000_000);

        $fresh = $repository->view($this->source(), 1_000_000 + 60);
        self::assertFalse($fresh['stalled'], 'a check a minute ago is not stalled');

        $stale = $repository->view($this->source(), 1_000_000 + (6 * 3600));
        self::assertTrue($stale['stalled'], 'six hours with no completed check is stalled');
    }

    public function testASourceThatHasNeverBeenCheckedIsStalled(): void
    {
        // The most dangerous state to render as healthy: nothing has ever been
        // verified, so the dashboard has no basis for any claim at all.
        $view = new SagamokMonitorRepository($this->manager())->view($this->source(), 2_000_000);

        self::assertTrue($view['stalled']);
    }

    // ------------------------------------------------------------------
    // Reappearance — spec §9.4
    // ------------------------------------------------------------------

    public function testReappearanceResetsTheCounterClearsStatusAndFiresOnce(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE], 1_000);

        $fetcher->remove(self::PAGE);
        $this->collector($fetcher)->run($this->source(), [self::PAGE], 2_000);
        $this->collector($fetcher)->run($this->source(), [self::PAGE], 3_000);

        $item = $this->items()[0];
        self::assertSame('disappeared', (string) $item->get('change_status'));

        // Identical bytes return. This must still count as a reappearance:
        // the page is being served again, and saying otherwise is false.
        $fetcher->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('A', 'body')));
        $return = $this->collector($fetcher)->run($this->source(), [self::PAGE], 4_000);

        self::assertSame(['reappeared'], array_column($return['events'], 'type'), 'exactly one reappearance');

        $item = $this->items()[0];
        self::assertSame('reappeared', (string) $item->get('change_status'));
        self::assertSame(0, (int) $item->get('disappeared_at'), 'the disappeared timestamp is cleared');
        self::assertSame(0, $this->absentRuns(), 'absent_runs is reset to zero');

        // And the next unchanged run is silent again.
        $steady = $this->collector($fetcher)->run($this->source(), [self::PAGE], 5_000);
        self::assertSame([], $steady['events']);
    }

    public function testAbsentThenFailureThenAbsentIsNotTwoConsecutiveAbsences(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE], 1_000);

        $fetcher->remove(self::PAGE);
        $first = $this->collector($fetcher)->run($this->source(), [self::PAGE], 2_000);
        self::assertSame(['absent_pending'], array_column($first['events'], 'type'));

        // A transport failure carries no information about the page existing.
        $fetcher->set(self::PAGE, FetchResult::failure(self::PAGE, 'timeout'));
        $failure = $this->collector($fetcher)->run($this->source(), [self::PAGE], 3_000);
        self::assertSame([], array_column($failure['events'], 'type'));

        self::assertSame(1, $this->absentRuns(), 'the failure run must not advance the absence counter');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function absentRuns(): int
    {
        $pdo = new \PDO('sqlite:' . $this->databasePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $rows = $pdo->query('SELECT absent_runs FROM ' . CollectorState::TABLE)->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === [] ? 0 : (int) $rows[0]['absent_runs'];
    }

    private function collector(FixturePageFetcher $fetcher): PublicSiteCollector
    {
        $manager = $this->manager();

        return new PublicSiteCollector(
            $manager->getRepository(MonitorEntityTypes::SOURCE),
            $manager->getRepository(MonitorEntityTypes::ITEM),
            $manager->getRepository(MonitorEntityTypes::EVENT),
            new CollectorState($this->database()),
            $fetcher,
        );
    }

    private function source(): MonitorSource
    {
        $repository = $this->manager()->getRepository(MonitorEntityTypes::SOURCE);
        foreach ($repository->findBy(['key' => self::SOURCE_KEY]) as $existing) {
            if ($existing instanceof MonitorSource) {
                return $existing;
            }
        }

        $source = $repository->create([
            'key' => self::SOURCE_KEY,
            'label' => 'Sagamok public website',
            'origin_url' => self::ORIGIN,
            'enabled' => true,
            'health' => 'ok',
        ]);
        $repository->save($source, validate: false);
        self::assertInstanceOf(MonitorSource::class, $source);

        return $source;
    }

    /** @return list<\Waaseyaa\Entity\EntityInterface> */
    private function items(): array
    {
        return array_values(iterator_to_array(
            $this->manager()->getRepository(MonitorEntityTypes::ITEM)->findBy([]),
            false,
        ));
    }

    private function page(string $title, string $body): string
    {
        return sprintf('<html><head><title>%s</title></head><body><p>%s</p></body></html>', $title, $body);
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
