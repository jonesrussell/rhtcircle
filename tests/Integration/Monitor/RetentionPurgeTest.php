<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Entity\MonitorSource;
use App\Monitor\CollectorState;
use App\Monitor\FetchResult;
use App\Monitor\FixturePageFetcher;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\PublicSiteCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Review finding 2: an exclusion must purge what was already retained.
 *
 * The collector's comment claimed "No hash, no snapshot, no body retained —
 * for either kind", and that was true only of the *current* run.
 * `recordExclusion()` never touched the snapshot table, so a page collected on
 * Monday and marked `noindex` on Tuesday left its body sitting in
 * `monitor_collector_snapshot` until the 90-day timer expired it. The
 * publisher's retention instruction was honoured forwards and ignored
 * backwards. Redaction had the same hole: it cleared `notes` and
 * `evidence_url` while the snapshot — which *is* the content — stayed.
 *
 * These tests **seed retained content first**, apply the transition, then read
 * the database directly. Asserting on the collector's return value would prove
 * nothing: the bug was never in what it reported, it was in what remained on
 * disk afterwards.
 */
final class RetentionPurgeTest extends TestCase
{
    private const SOURCE_KEY = 'sagamok_public_site';
    private const ORIGIN = 'https://www.sagamokanishnawbek.test/';
    private const PAGE = self::ORIGIN . 'notices/water-advisory';
    private const OTHER = self::ORIGIN . 'notices/road-closure';

    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?HttpKernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-purge-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=purge-test-secret');
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
    public function aNoindexTransitionPurgesEveryPreviouslyRetainedSnapshot(): void
    {
        $fetcher = $this->seededFetcher();

        // Positive control: without this, the purge assertion below could pass
        // simply because nothing was ever stored.
        self::assertGreaterThan(
            0,
            $this->snapshotCount(),
            'the fixture must actually retain a body, or this test proves nothing',
        );
        self::assertNotSame('', $this->storedContentHash(), 'a content hash must have been retained');

        // The publisher now asks us not to retain this page.
        $fetcher->set(self::PAGE, FetchResult::success(
            200,
            self::PAGE,
            '<html><head><meta name="robots" content="noindex"></head><body>water</body></html>',
        ));
        $this->collector($fetcher)->run($this->source(), [self::PAGE], 2_000);

        self::assertSame(0, $this->snapshotCount(), 'every retained snapshot must be gone');
        self::assertSame('', $this->storedContentHash(), 'the content hash is content-derived and must go too');
    }

    #[Test]
    public function aGatingTransitionPurgesEveryPreviouslyRetainedSnapshot(): void
    {
        $fetcher = $this->seededFetcher();
        self::assertGreaterThan(0, $this->snapshotCount());

        // The page moved behind the members portal: material we are no longer
        // entitled to hold.
        $fetcher->set(self::PAGE, FetchResult::success(401, self::PAGE, ''));
        $this->collector($fetcher)->run($this->source(), [self::PAGE], 2_000);

        self::assertSame(0, $this->snapshotCount());
        self::assertSame('', $this->storedContentHash());
    }

    #[Test]
    public function thePurgeRemovesOnlyTheExcludedItem(): void
    {
        // Mutation control for both tests above: a purge that simply emptied
        // the table would satisfy them while destroying unrelated evidence.
        $fetcher = FixturePageFetcher::withPages([
            self::PAGE => $this->page('Water', 'water'),
            self::OTHER => $this->page('Roads', 'roads'),
        ]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE, self::OTHER], 1_000);
        self::assertSame(2, $this->snapshotCount(), 'both pages retained');

        $fetcher->set(self::PAGE, FetchResult::success(401, self::PAGE, ''));
        $this->collector($fetcher)->run($this->source(), [self::PAGE, self::OTHER], 2_000);

        self::assertSame(1, $this->snapshotCount(), 'the unaffected page keeps its snapshot');
    }

    #[Test]
    public function recoveryAfterExclusionDoesNotClaimTheContentChanged(): void
    {
        // The consequence of purging by policy: on the way back out there is no
        // fingerprint to compare against, so we cannot honestly say the page
        // changed. Saying so anyway would be an assertion about the Nation's
        // website whose evidence we deliberately destroyed.
        $fetcher = $this->seededFetcher();

        $fetcher->set(self::PAGE, FetchResult::success(401, self::PAGE, ''));
        $this->collector($fetcher)->run($this->source(), [self::PAGE], 2_000);

        // Byte-identical to the original.
        $fetcher->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('Water', 'water')));
        $recovery = $this->collector($fetcher)->run($this->source(), [self::PAGE], 3_000);

        self::assertSame(
            ['became_retainable'],
            array_column($recovery['events'], 'type'),
            'recovery must not be reported as a content change',
        );
        self::assertNotContains(
            'content_changed',
            $this->eventTypes(),
            'and no content_changed event may reach the database either',
        );
    }

    #[Test]
    public function purgeRetainedContentReachesTheItemBehindAPublicRef(): void
    {
        // The path redaction needs: an operator holds a public ref, and the
        // snapshot is keyed by item key. Without this lookup, redaction can
        // only clear the locator and leaves the content.
        $this->seededFetcher();
        self::assertGreaterThan(0, $this->snapshotCount());

        $publicRef = $this->firstPublicRef();
        self::assertNotSame('', $publicRef, 'the fixture must have produced a public ref');

        $state = new CollectorState($this->database());
        $itemKey = $state->itemKeyForPublicRef(self::SOURCE_KEY, $publicRef);
        self::assertNotNull($itemKey, 'redaction must be able to reach the item behind a public ref');

        $removed = $state->purgeRetainedContent(self::SOURCE_KEY, $itemKey);

        self::assertGreaterThan(0, $removed, 'purge must report what it removed');
        self::assertSame(0, $this->snapshotCount(), 'the retained copy must be gone');
    }

    // ------------------------------------------------------------------

    private function seededFetcher(): FixturePageFetcher
    {
        $fetcher = FixturePageFetcher::withPages([self::PAGE => $this->page('Water', 'water')]);
        $this->collector($fetcher)->run($this->source(), [self::PAGE], 1_000);

        return $fetcher;
    }

    private function snapshotCount(): int
    {
        return count($this->rowsOf(CollectorState::SNAPSHOT_TABLE));
    }

    private function storedContentHash(): string
    {
        $rows = $this->rowsOf(CollectorState::TABLE);

        return $rows === [] ? '' : (string) ($rows[0]['content_hash'] ?? '');
    }

    private function firstPublicRef(): string
    {
        foreach ($this->rowsOf(CollectorState::TABLE) as $row) {
            $ref = (string) ($row['item_public_ref'] ?? '');
            if ($ref !== '') {
                return $ref;
            }
        }

        return '';
    }

    /** @return list<array<string, mixed>> */
    private function rowsOf(string $table): array
    {
        $pdo = new \PDO('sqlite:' . $this->databasePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $table . "'")
            ->fetchAll(\PDO::FETCH_ASSOC);
        if ($exists === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $pdo->query('SELECT * FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /** @return list<string> */
    private function eventTypes(): array
    {
        $types = [];
        foreach ($this->rowsOf('monitor_event') as $row) {
            $types[] = (string) ($row['event_type'] ?? '');
        }

        return $types;
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

        return $source;
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
