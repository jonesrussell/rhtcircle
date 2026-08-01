<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Entity\MonitorSource;
use App\Monitor\CollectorState;
use App\Monitor\ExclusionKind;
use App\Monitor\FetchResult;
use App\Monitor\FixturePageFetcher;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\PublicSiteCollector;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Spec §9.4 end-to-end: the collector against a **real kernel**, real entity
 * storage and the real side table.
 *
 * Every fetch is served from {@see FixturePageFetcher}. There is no network
 * path a test could take even by accident: `PageFetcherInterface` exposes no
 * host, credential or transport, so the production Sagamok site cannot be
 * contacted from this file.
 */
final class CollectorRunTest extends TestCase
{
    private const SOURCE_KEY = 'sagamok_public_site';
    private const ORIGIN = 'https://www.sagamokanishnawbek.test/';
    private const PAGE_A = self::ORIGIN . 'notices/water-advisory';
    private const PAGE_B = self::ORIGIN . 'notices/road-closure';

    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?HttpKernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-collector-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }

        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=collector-test-secret');
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
    // Idempotence — the single most important behavioural property
    // ------------------------------------------------------------------

    public function testAnIdenticalSecondRunProducesNoEventsAndNoChanges(): void
    {
        $fetcher = FixturePageFetcher::withPages([
            self::PAGE_A => $this->page('Water advisory', 'The advisory remains in effect.'),
        ]);

        $first = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);
        self::assertCount(1, $first['events'], 'the first run establishes the item');

        $eventsAfterFirst = $this->countEvents();

        $second = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 2_000);

        self::assertSame([], $second['events'], 'unchanged upstream state must produce zero events');
        self::assertSame($eventsAfterFirst, $this->countEvents(), 'and must write no new event rows');
        self::assertSame(1, $this->countItems(), 'and must not mint a second item');
    }

    public function testAVolatileOnlyChangeIsNotAChange(): void
    {
        // The normalizer's whole purpose, proven end to end: a rotating asset
        // hash must not appear on the dashboard as "this document changed".
        $fetcher = FixturePageFetcher::withPages([
            self::PAGE_A => '<html><head><link href="/a.111111111111.css"><title>T</title></head><body>Same</body></html>',
        ]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->set(self::PAGE_A, FetchResult::success(
            200,
            self::PAGE_A,
            '<html><head><link href="/a.999999999999.css"><title>T</title></head><body>Same</body></html>',
        ));
        $second = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 2_000);

        self::assertSame([], $second['events']);
    }

    public function testARealEditProducesExactlyOneContentChangedEvent(): void
    {
        $fetcher = FixturePageFetcher::withPages([
            self::PAGE_A => $this->page('Water advisory', 'The advisory remains in effect.'),
        ]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->set(self::PAGE_A, FetchResult::success(
            200,
            self::PAGE_A,
            $this->page('Water advisory', 'The advisory has been lifted.'),
        ));
        $second = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 2_000);

        $types = array_column($second['events'], 'type');
        self::assertSame(['content_changed'], $types);
    }

    // ------------------------------------------------------------------
    // Disappearance requires two SUCCESSFUL absent observations
    // ------------------------------------------------------------------

    public function testOneAbsentRunDoesNotPublishARemoval(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->remove(self::PAGE_A);
        $second = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 2_000);

        self::assertSame(['absent_pending'], array_column($second['events'], 'type'));
        self::assertNotContains('disappeared', array_column($second['events'], 'type'));
    }

    public function testTwoConsecutiveAbsentRunsPublishExactlyOneRemoval(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->remove(self::PAGE_A);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 2_000);
        $third = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 3_000);

        self::assertSame(['disappeared'], array_column($third['events'], 'type'));

        // And not again on a fourth run — a removal is announced once.
        $fourth = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 4_000);
        self::assertNotContains('disappeared', array_column($fourth['events'], 'type'));
    }

    public function testAFetchFailureIsNotAnAbsentObservation(): void
    {
        // The rule this protects: an unreachable site is not a site that
        // removed a page. Two timeouts in a row must never publish a removal.
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->set(self::PAGE_A, FetchResult::failure(self::PAGE_A, 'connection timed out'));
        $second = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 2_000);
        $third = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 3_000);

        foreach ([$second, $third] as $run) {
            self::assertNotContains('disappeared', array_column($run['events'], 'type'));
            self::assertNotContains('absent_pending', array_column($run['events'], 'type'));
            self::assertSame(1, $run['fetch_failures']);
        }
    }

    public function testAFailureBetweenTwoAbsencesDoesNotAccumulateTowardsRemoval(): void
    {
        // absent, failure, absent must NOT equal two consecutive absences.
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->remove(self::PAGE_A);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 2_000);

        $fetcher->set(self::PAGE_A, FetchResult::failure(self::PAGE_A, 'timeout'));
        $failureRun = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 3_000);

        self::assertNotContains('disappeared', array_column($failureRun['events'], 'type'));
    }

    public function testReappearanceClearsTheDisappearedTimestamp(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->remove(self::PAGE_A);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 2_000);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 3_000);

        $fetcher->set(self::PAGE_A, FetchResult::success(200, self::PAGE_A, $this->page('A', 'body')));
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 4_000);

        $item = $this->items()[0];
        self::assertSame(0, (int) $item->get('disappeared_at'), 'disappeared_at must be cleared on return');
    }

    // ------------------------------------------------------------------
    // Exclusions store nothing — both kinds
    // ------------------------------------------------------------------

    public function testAnAuthRequiredExclusionStoresNoBodyHashOrSnapshot(): void
    {
        $fetcher = new FixturePageFetcher()->set(
            self::PAGE_A,
            FetchResult::success(401, self::PAGE_A, '<html><body>Members only</body></html>'),
        );

        $run = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        self::assertSame(1, $run['gated']);
        self::assertSame(0, $run['not_retained']);
        $this->assertExclusionRetainedNoContent(ExclusionKind::AuthRequired);
    }

    public function testANotForRetentionExclusionStoresNoBodyHashOrSnapshot(): void
    {
        $fetcher = new FixturePageFetcher()->set(
            self::PAGE_A,
            FetchResult::success(
                200,
                self::PAGE_A,
                '<html><head><meta name="robots" content="noindex"></head><body>Public but unretained</body></html>',
            ),
        );

        $run = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        self::assertSame(1, $run['not_retained'], 'noindex is counted separately from gated');
        self::assertSame(0, $run['gated'], 'noindex must never inflate the sign-in figure');
        $this->assertExclusionRetainedNoContent(ExclusionKind::NotForRetention);
    }

    public function testNoindexDoesNotDegradeSourceHealth(): void
    {
        // The site is reachable and behaving normally; honouring the directive
        // is not a monitoring fault.
        $fetcher = new FixturePageFetcher()->set(
            self::PAGE_A,
            FetchResult::success(200, self::PAGE_A, '<html><head><meta name="robots" content="noindex"></head><body>x</body></html>'),
        );

        $run = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        self::assertSame('ok', $run['health']);
        self::assertSame('ok', (string) $this->source()->get('health'));
    }

    // ------------------------------------------------------------------
    // Renames: no invented identity
    // ------------------------------------------------------------------

    public function testARenameWithIdenticalContentReusesTheExistingPublicRef(): void
    {
        $body = $this->page('Water advisory', 'The advisory remains in effect.');
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $body]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $originalRef = (string) $this->items()[0]->get('public_ref');

        // Upstream renames the URL; the content is byte-identical.
        $fetcher->remove(self::PAGE_A);
        $fetcher->set(self::PAGE_B, FetchResult::success(200, self::PAGE_B, $body));
        $second = $this->collector($fetcher)->run($this->source(), [self::PAGE_A, self::PAGE_B], 2_000);

        self::assertContains('reappeared', array_column($second['events'], 'type'));
        self::assertSame(1, $this->countItems(), 'a rename must not mint a second item');
        self::assertSame($originalRef, (string) $this->items()[0]->get('public_ref'), 'the public ref is stable across a rename');
    }

    public function testADifferentDocumentAtANewUrlIsANewItem(): void
    {
        // The counterweight: the near-duplicate guard must not merge two
        // genuinely different documents into one history.
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'first')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->set(self::PAGE_B, FetchResult::success(200, self::PAGE_B, $this->page('B', 'second')));
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A, self::PAGE_B], 2_000);

        self::assertSame(2, $this->countItems());
    }

    public function testOffOriginUrlsAreNeverFetched(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'body')]);

        $run = $this->collector($fetcher)->run($this->source(), [
            self::PAGE_A,
            'https://members.sagamokanishnawbek.test/portal/documents',
            'https://web.archive.org/web/2024/https://www.sagamokanishnawbek.test/',
        ], 1_000);

        self::assertSame(2, $run['skipped_off_origin']);
        foreach ($fetcher->requestedUrls() as $requested) {
            self::assertStringNotContainsString('members.', $requested);
            self::assertStringNotContainsString('archive.org', $requested);
        }
    }

    // ------------------------------------------------------------------
    // Dry run writes nothing at all
    // ------------------------------------------------------------------

    public function testDryRunWritesNothing(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'body')]);

        $run = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000, dryRun: true);

        self::assertTrue($run['dry_run']);
        self::assertNotSame([], $run['events'], 'a dry run must still report what it would do');
        self::assertSame(0, $this->countItems(), 'no entity written');
        self::assertSame(0, $this->countEvents(), 'no event written');
        self::assertSame([], $this->sideTableRows(), 'no side-table row written');
        self::assertSame(0, (int) $this->source()->get('last_check_completed'), 'health untouched');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

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

    private function countItems(): int
    {
        return count($this->items());
    }

    private function countEvents(): int
    {
        return count(array_values(iterator_to_array(
            $this->manager()->getRepository(MonitorEntityTypes::EVENT)->findBy([]),
            false,
        )));
    }

    /**
     * The exclusion STATE is persisted — that is how a gated page emits one
     * event rather than one per run — but none of the excluded page's content
     * may be. Assert both halves together so neither can drift.
     */
    private function assertExclusionRetainedNoContent(ExclusionKind $expected): void
    {
        $rows = $this->sideTableRows();
        self::assertCount(1, $rows, 'the exclusion state is remembered');
        self::assertSame($expected->value, $rows[0]['exclusion_kind']);

        self::assertSame('', (string) $rows[0]['content_hash'], 'no hash of excluded content');
        self::assertSame(0, (int) $rows[0]['normalized_bytes'], 'no size of excluded content');
        self::assertSame([], $this->snapshotRows(), 'no snapshot of excluded content');
    }

    /** @return list<array<string, mixed>> */
    private function snapshotRows(): array
    {
        $pdo = new \PDO('sqlite:' . $this->databasePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . CollectorState::SNAPSHOT_TABLE . "'")
            ->fetchAll(\PDO::FETCH_ASSOC);
        if ($tables === []) {
            return [];
        }

        return $pdo->query('SELECT * FROM ' . CollectorState::SNAPSHOT_TABLE)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function sideTableRows(): array
    {
        $pdo = new \PDO('sqlite:' . $this->databasePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . CollectorState::TABLE . "'")
            ->fetchAll(\PDO::FETCH_ASSOC);
        if ($tables === []) {
            return [];
        }

        return $pdo->query('SELECT * FROM ' . CollectorState::TABLE)->fetchAll(\PDO::FETCH_ASSOC);
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
