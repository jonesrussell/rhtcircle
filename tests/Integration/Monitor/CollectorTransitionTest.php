<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Entity\MonitorSource;
use App\Monitor\CollectorState;
use App\Monitor\ContentNormalizer;
use App\Monitor\FetchResult;
use App\Monitor\FixturePageFetcher;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\PublicSiteCollector;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Review findings 4, 5, 6 and 7: status-code transitions, exclusion
 * transitions, move detection, and snapshot retention.
 *
 * Every one of these is a case where the collector previously published
 * something untrue — a removal that had not happened, an event every hour for
 * one unchanged fact, two separate pages merged into one history, or a
 * retention policy that existed only in a comment.
 */
final class CollectorTransitionTest extends TestCase
{
    private const SOURCE_KEY = 'sagamok_public_site';
    private const ORIGIN = 'https://www.sagamokanishnawbek.test/';
    private const PAGE_A = self::ORIGIN . 'notices/water-advisory';
    private const PAGE_B = self::ORIGIN . 'notices/water-advisory-2026';

    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?HttpKernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-transition-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=transition-test-secret');
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
    // 4. Only confirmed-absence statuses may advance absence
    // ------------------------------------------------------------------

    /** @return iterable<string, array{int, bool}> */
    public static function statusCodes(): iterable
    {
        yield '404 Not Found' => [404, true];
        yield '410 Gone' => [410, true];
        yield '500 Internal Server Error' => [500, false];
        yield '502 Bad Gateway' => [502, false];
        yield '503 Service Unavailable' => [503, false];
        yield '429 Too Many Requests' => [429, false];
        yield '408 Request Timeout' => [408, false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusCodes')]
    public function testOnlyConfirmedAbsenceStatusesCanPublishARemoval(int $status, bool $isAbsence): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->set(self::PAGE_A, FetchResult::success($status, self::PAGE_A, ''));
        $second = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 2_000);
        $third = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 3_000);

        $types = array_merge(array_column($second['events'], 'type'), array_column($third['events'], 'type'));

        if ($isAbsence) {
            self::assertContains('disappeared', $types, sprintf('%d states the resource is gone', $status));
        } else {
            self::assertNotContains(
                'disappeared',
                $types,
                sprintf('%d means the server could not answer, not that the page was removed', $status),
            );
            self::assertNotContains('absent_pending', $types, 'and it must not advance the counter either');
        }
    }

    public function testRepeatedServerErrorsDegradeHealthWithoutRemoving(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->set(self::PAGE_A, FetchResult::success(500, self::PAGE_A, 'oops'));
        for ($i = 2; $i <= 5; ++$i) {
            $run = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], $i * 1_000);
            self::assertNotContains('disappeared', array_column($run['events'], 'type'));
        }

        self::assertNotSame('ok', (string) $this->source()->get('health'), 'health carries the problem instead');
        self::assertSame(0, $this->absentRuns(), 'the absence counter never moved');
    }

    // ------------------------------------------------------------------
    // 5. Exclusions fire once, and recover
    // ------------------------------------------------------------------

    public function testAGatedPageEmitsExactlyOneEventAcrossManyRuns(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->set(self::PAGE_A, FetchResult::success(401, self::PAGE_A, ''));

        $emitted = 0;
        for ($i = 2; $i <= 5; ++$i) {
            $run = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], $i * 1_000);
            $emitted += count(array_filter(array_column($run['events'], 'type'), static fn (string $t): bool => $t === 'became_gated'));
        }

        self::assertSame(1, $emitted, 'one transition, one event — not one per run');
        self::assertSame(1, $this->countEventsOfType('became_gated'));
    }

    public function testANoindexPageEmitsExactlyOneEventAndLeavesHealthAlone(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->set(self::PAGE_A, FetchResult::success(
            200,
            self::PAGE_A,
            '<html><head><meta name="robots" content="noindex"></head><body>x</body></html>',
        ));

        $emitted = 0;
        for ($i = 2; $i <= 4; ++$i) {
            $run = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], $i * 1_000);
            $emitted += count(array_filter(array_column($run['events'], 'type'), static fn (string $t): bool => $t === 'became_noindex'));
            self::assertSame(0, $run['gated'], 'noindex never counts as a sign-in wall');
        }

        self::assertSame(1, $emitted);
    }

    public function testAPageThatBecomesRetainableAgainIsAnnouncedOnce(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'body')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->set(self::PAGE_A, FetchResult::success(401, self::PAGE_A, ''));
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 2_000);

        // Public again.
        $fetcher->set(self::PAGE_A, FetchResult::success(200, self::PAGE_A, $this->page('A', 'body')));
        $recovery = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 3_000);
        self::assertSame(['became_retainable'], array_column($recovery['events'], 'type'));

        // And the run after that is silent again.
        $steady = $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 4_000);
        self::assertSame([], $steady['events']);
    }

    public function testAnExcludedPageStoresNoContentEvenAcrossRepeatedRuns(): void
    {
        $fetcher = new FixturePageFetcher()->set(
            self::PAGE_A,
            FetchResult::success(403, self::PAGE_A, '<html><body>secret material</body></html>'),
        );

        for ($i = 1; $i <= 3; ++$i) {
            $this->collector($fetcher)->run($this->source(), [self::PAGE_A], $i * 1_000);
        }

        $rows = $this->sideTableRows();
        self::assertCount(1, $rows);
        self::assertSame('', (string) $rows[0]['content_hash']);
        self::assertSame([], $this->snapshotRows(), 'no snapshot of excluded material, ever');
    }

    // ------------------------------------------------------------------
    // 6. Moves versus two live pages
    // ------------------------------------------------------------------

    public function testTwoLivePagesWithIdenticalContentStaySeparate(): void
    {
        // The defect: identical content at a second URL looked like a move even
        // though the first URL was still being served, merging two genuinely
        // separate pages into one history.
        $body = $this->page('Water advisory', 'The advisory remains in effect.');
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $body]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->set(self::PAGE_B, FetchResult::success(200, self::PAGE_B, $body));
        $second = $this->collector($fetcher)->run($this->source(), [self::PAGE_A, self::PAGE_B], 2_000);

        self::assertContains('appeared', array_column($second['events'], 'type'), 'the second URL is a new item');
        self::assertNotContains('reappeared', array_column($second['events'], 'type'), 'nothing moved: both are live');
        self::assertSame(2, $this->countItems());
    }

    public function testARealRenamePreservesOnePublicRefAndOneKey(): void
    {
        $body = $this->page('Water advisory', 'The advisory remains in effect.');
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $body]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);
        $originalRef = (string) $this->items()[0]->get('public_ref');

        // Old URL now 404s; identical content served at the new URL.
        $fetcher->set(self::PAGE_A, FetchResult::success(404, self::PAGE_A, ''));
        $fetcher->set(self::PAGE_B, FetchResult::success(200, self::PAGE_B, $body));
        $second = $this->collector($fetcher)->run($this->source(), [self::PAGE_A, self::PAGE_B], 2_000);

        self::assertContains('reappeared', array_column($second['events'], 'type'));
        self::assertSame(1, $this->countItems(), 'a rename must not mint a second item');
        self::assertSame($originalRef, (string) $this->items()[0]->get('public_ref'));

        // Exactly one active key holds the ref.
        self::assertCount(1, $this->state()->keysForPublicRef(self::SOURCE_KEY, $originalRef));
    }

    public function testTheOldKeyNeverProducesALaterFalseDisappearance(): void
    {
        // The second half of the same defect: even when the rename was detected,
        // the stale key stayed in the table and went absent on later runs,
        // publishing a removal for a page being served happily at its new URL.
        $body = $this->page('Water advisory', 'In effect.');
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $body]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $fetcher->set(self::PAGE_A, FetchResult::success(404, self::PAGE_A, ''));
        $fetcher->set(self::PAGE_B, FetchResult::success(200, self::PAGE_B, $body));
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A, self::PAGE_B], 2_000);

        // Several more runs where the old URL keeps 404ing.
        for ($i = 3; $i <= 6; ++$i) {
            $run = $this->collector($fetcher)->run($this->source(), [self::PAGE_A, self::PAGE_B], $i * 1_000);
            self::assertNotContains(
                'disappeared',
                array_column($run['events'], 'type'),
                'the moved item is alive at its new URL; the old key must not report it gone',
            );
        }

        self::assertSame('reappeared', (string) $this->items()[0]->get('change_status'));
        self::assertSame(0, (int) $this->items()[0]->get('disappeared_at'));
    }

    // ------------------------------------------------------------------
    // 7. Snapshot retention
    // ------------------------------------------------------------------

    public function testTheSnapshotHistoryIsCappedAtThreePerItem(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'v1')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        foreach (['v2', 'v3', 'v4', 'v5'] as $i => $version) {
            $fetcher->set(self::PAGE_A, FetchResult::success(200, self::PAGE_A, $this->page('A', $version)));
            $this->collector($fetcher)->run($this->source(), [self::PAGE_A], ($i + 2) * 1_000);
        }

        $key = array_key_first($this->state()->allForSource(self::SOURCE_KEY));
        $snapshots = $this->state()->snapshotsFor(self::SOURCE_KEY, (string) $key);

        self::assertCount(CollectorState::MAX_SNAPSHOTS_PER_ITEM, $snapshots, 'exactly three are retained');
        self::assertSame(3, CollectorState::MAX_SNAPSHOTS_PER_ITEM);

        // The newest are kept, not the oldest.
        self::assertSame(ContentNormalizer::hash($this->page('A', 'v5')), $snapshots[0]['content_hash']);
    }

    public function testUnchangedContentDoesNotConsumeSnapshotHistory(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'v1')]);
        for ($i = 1; $i <= 4; ++$i) {
            $this->collector($fetcher)->run($this->source(), [self::PAGE_A], $i * 1_000);
        }

        $key = array_key_first($this->state()->allForSource(self::SOURCE_KEY));
        self::assertCount(1, $this->state()->snapshotsFor(self::SOURCE_KEY, (string) $key));
    }

    public function testSnapshotsOlderThanNinetyDaysArePruned(): void
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE_A => $this->page('A', 'v1')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000_000);

        $key = (string) array_key_first($this->state()->allForSource(self::SOURCE_KEY));
        self::assertCount(1, $this->state()->snapshotsFor(self::SOURCE_KEY, $key));

        // A run 91 days later prunes it.
        $later = 1_000_000 + (91 * 86_400);
        $fetcher->set(self::PAGE_A, FetchResult::success(200, self::PAGE_A, $this->page('A', 'v2')));
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], $later);

        $remaining = $this->state()->snapshotsFor(self::SOURCE_KEY, $key);
        self::assertCount(1, $remaining, 'the expired snapshot is gone; the fresh one remains');
        self::assertSame($later, $remaining[0]['taken_at']);
        self::assertSame(90, CollectorState::SNAPSHOT_RETENTION_DAYS);
    }

    public function testASnapshotIsCappedAtTwoMegabytes(): void
    {
        $huge = '<html><body>' . str_repeat('a', 3 * 1024 * 1024) . '</body></html>';
        $fetcher = new FixturePageFetcher()->set(self::PAGE_A, FetchResult::success(200, self::PAGE_A, $huge));
        $this->collector($fetcher)->run($this->source(), [self::PAGE_A], 1_000);

        $key = (string) array_key_first($this->state()->allForSource(self::SOURCE_KEY));
        $snapshots = $this->state()->snapshotsFor(self::SOURCE_KEY, $key);

        self::assertCount(1, $snapshots);
        self::assertLessThanOrEqual(ContentNormalizer::MAX_SNAPSHOT_BYTES, $snapshots[0]['snapshot_bytes']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function state(): CollectorState
    {
        return new CollectorState($this->database());
    }

    private function absentRuns(): int
    {
        $rows = $this->sideTableRows();

        return $rows === [] ? 0 : (int) $rows[0]['absent_runs'];
    }

    /** @return list<array<string, mixed>> */
    private function sideTableRows(): array
    {
        $pdo = new \PDO('sqlite:' . $this->databasePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . CollectorState::TABLE . "'")->fetchAll(\PDO::FETCH_ASSOC);

        return $tables === [] ? [] : $pdo->query('SELECT * FROM ' . CollectorState::TABLE)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function snapshotRows(): array
    {
        $pdo = new \PDO('sqlite:' . $this->databasePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . CollectorState::SNAPSHOT_TABLE . "'")->fetchAll(\PDO::FETCH_ASSOC);

        return $tables === [] ? [] : $pdo->query('SELECT * FROM ' . CollectorState::SNAPSHOT_TABLE)->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function countEventsOfType(string $type): int
    {
        $n = 0;
        foreach ($this->manager()->getRepository(MonitorEntityTypes::EVENT)->findBy([]) as $event) {
            if ((string) $event->get('event_type') === $type) {
                ++$n;
            }
        }

        return $n;
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
        return array_values(iterator_to_array($this->manager()->getRepository(MonitorEntityTypes::ITEM)->findBy([]), false));
    }

    private function countItems(): int
    {
        return count($this->items());
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
