<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Command\MonitorRedactEventCommand;
use App\Entity\MonitorSource;
use App\Monitor\CollectorState;
use App\Monitor\FetchResult;
use App\Monitor\FixturePageFetcher;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\PublicSiteCollector;
use App\Monitor\SagamokMonitorRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * The collector as a state machine, audited transition by transition.
 *
 * Every defect this feature has produced has been a *transition* defect rather
 * than a step defect: an orphan event on recovery, a title stuck at a stale
 * exclusion label, a redaction undone by the next crawl, a removal published
 * after one absent run. Each individual step was defensible; the sequence was
 * not.
 *
 * So this asserts the whole observable surface after each transition — item
 * existence, public reference, event types and counts, title, hash, snapshot
 * count, public projection, rendered wording — rather than the one field a
 * given fix happened to touch. A regression that trades one of those for
 * another fails here even if the fix's own test still passes.
 */
final class MonitorStateTransitionAuditTest extends TestCase
{
    private const SOURCE_KEY = 'sagamok_public_site';
    private const ORIGIN = 'https://www.sagamokanishnawbek.test/';
    private const PAGE = self::ORIGIN . 'notices/water-advisory';
    private const MOVED_PAGE = self::ORIGIN . 'updates/water-advisory';

    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?HttpKernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-fsm-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=fsm-test-secret');
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
    // The global invariant
    // ------------------------------------------------------------------

    #[Test]
    public function noStoredEventEverHasAnEmptyPublicRef(): void
    {
        // Asserted after driving every transition in this file through one
        // database. This is the invariant the persistence boundary enforces;
        // here it is checked against what actually landed on disk.
        $this->driveEveryTransition();

        $orphans = array_filter(
            $this->rows('monitor_event'),
            static fn (array $r): bool => (string) ($r['item_public_ref'] ?? '') === '',
        );

        self::assertSame([], $orphans, 'a monitor event must always name the item it is about');
        self::assertNotEmpty($this->rows('monitor_event'), 'and events must actually have been written');
    }

    #[Test]
    public function theEventBoundaryRejectsAnEmptyReferenceForEveryEventType(): void
    {
        // The boundary itself, exercised directly. A guard in individual
        // branches only holds until someone adds the next branch — which is
        // exactly how this defect reappeared a third time.
        $collector = $this->collector(FixturePageFetcher::withPages([]));
        $method = new \ReflectionMethod($collector, 'recordEvent');

        foreach ([
            'appeared', 'content_changed', 'disappeared', 'reappeared',
            'became_gated', 'became_noindex', 'became_retainable',
        ] as $type) {
            try {
                $method->invoke($collector, self::SOURCE_KEY, '', $type, 1_000, 'direct_fetch', '');
                self::fail(sprintf('"%s" was persisted with an empty public reference', $type));
            } catch (\LogicException $e) {
                self::assertStringContainsString($type, $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // Transition matrix
    // ------------------------------------------------------------------

    #[Test]
    public function unseenToPublic(): void
    {
        $f = $this->fixture($this->page('Water advisory', 'body'));
        $this->collector($f)->run($this->source(), [self::PAGE], 1_000);

        $this->assertItem(exists: true, title: 'Water advisory', hashEmpty: false, snapshots: 1);
        self::assertSame(['appeared'], $this->eventTypes());
        self::assertNotSame('', $this->publicRef());
    }

    #[Test]
    public function unseenToGatedToPublic(): void
    {
        // The defect this file was written for: first observed WHILE gated, so
        // there is no public history. Recovery must be a first sighting, not a
        // "became_retainable" about a past that never existed.
        $f = $this->fixture('');
        $f->set(self::PAGE, FetchResult::success(401, self::PAGE, ''));
        $this->collector($f)->run($this->source(), [self::PAGE], 1_000);

        self::assertSame([], $this->eventTypes(), 'a never-acknowledged page emits nothing');
        $this->assertItem(exists: false);

        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('Now public', 'body')));
        $this->collector($f)->run($this->source(), [self::PAGE], 2_000);

        self::assertSame(['appeared'], $this->eventTypes(), 'first public sighting is an appearance');
        self::assertNotContains('became_retainable', $this->eventTypes());
        $this->assertItem(exists: true, title: 'Now public', hashEmpty: false, snapshots: 1);
        self::assertNotSame('', $this->publicRef(), 'the item is now publicly acknowledged');

        // And it self-heals: a later change behaves normally rather than
        // accumulating orphans.
        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('Now public', 'changed body')));
        $this->collector($f)->run($this->source(), [self::PAGE], 3_000);
        self::assertSame(['appeared', 'content_changed'], $this->eventTypes());
    }

    #[Test]
    public function unseenToNoindexToPublic(): void
    {
        $f = $this->fixture('');
        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->noindexPage()));
        $this->collector($f)->run($this->source(), [self::PAGE], 1_000);

        self::assertSame([], $this->eventTypes());
        $this->assertItem(exists: false);

        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('Retainable now', 'body')));
        $this->collector($f)->run($this->source(), [self::PAGE], 2_000);

        self::assertSame(['appeared'], $this->eventTypes());
        $this->assertItem(exists: true, title: 'Retainable now', hashEmpty: false, snapshots: 1);
    }

    #[Test]
    public function publicToGatedToPublicRestoresTheTitle(): void
    {
        $f = $this->fixture($this->page('Original title', 'body'));
        $this->collector($f)->run($this->source(), [self::PAGE], 1_000);

        $f->set(self::PAGE, FetchResult::success(401, self::PAGE, ''));
        $this->collector($f)->run($this->source(), [self::PAGE], 2_000);
        $this->assertItem(exists: true, title: 'Page requiring sign-in (title not retained)', hashEmpty: true, snapshots: 0);

        // Recovery with a CHANGED title — the title may have moved while gated,
        // and the old one was purged, so it must be re-read from the body.
        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('Renamed while gated', 'body')));
        $this->collector($f)->run($this->source(), [self::PAGE], 3_000);

        self::assertSame(['appeared', 'became_gated', 'became_retainable'], $this->eventTypes());
        $this->assertItem(exists: true, title: 'Renamed while gated', hashEmpty: false, snapshots: 1);
        self::assertStringContainsString('Renamed while gated', $this->renderedDashboard());
        self::assertStringNotContainsString('title not retained', $this->renderedDashboard());
    }

    #[Test]
    public function publicToNoindexToPublicRestoresTheTitle(): void
    {
        $f = $this->fixture($this->page('Original title', 'body'));
        $this->collector($f)->run($this->source(), [self::PAGE], 1_000);

        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->noindexPage()));
        $this->collector($f)->run($this->source(), [self::PAGE], 2_000);
        $this->assertItem(exists: true, title: 'Page not retained at publisher request', hashEmpty: true, snapshots: 0);

        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('Retainable again', 'body')));
        $this->collector($f)->run($this->source(), [self::PAGE], 3_000);

        self::assertSame(['appeared', 'became_noindex', 'became_retainable'], $this->eventTypes());
        $this->assertItem(exists: true, title: 'Retainable again', hashEmpty: false, snapshots: 1);
    }

    #[Test]
    public function publicToRedactedToLaterIdenticalContentStaysSuppressed(): void
    {
        $eventId = $this->seedAndRedact();

        // Identical content on the next crawl.
        $f = $this->fixture($this->page('Original title', 'body'));
        $this->collector($f)->run($this->source(), [self::PAGE], 4_000);

        $this->assertSuppressed();
        self::assertSame(0, $this->snapshotCount(), 'identical content must not re-snapshot');
        self::assertNotSame(0, (int) $this->eventRow($eventId)['redacted_at'], 'the stub survives');
    }

    #[Test]
    public function publicToRedactedToLaterChangedContentStaysSuppressed(): void
    {
        // The case that made redaction non-durable: a CHANGED body previously
        // re-hashed, re-snapshotted and restored the locator.
        $this->seedAndRedact();

        $f = $this->fixture($this->page('Original title', 'COMPLETELY DIFFERENT BODY'));
        $this->collector($f)->run($this->source(), [self::PAGE], 4_000);

        $this->assertSuppressed();
        self::assertStringNotContainsString('COMPLETELY DIFFERENT BODY', $this->renderedDashboard());
    }

    #[Test]
    public function redactedContentMovedToANewUrlStaysSuppressed(): void
    {
        $this->seedAndRedact();
        $originalPublicRef = $this->publicRef();
        $beforeEvents = $this->eventTypes();

        $f = FixturePageFetcher::withPages([]);
        $f->set(self::PAGE, FetchResult::success(404, self::PAGE, ''));
        $f->set(
            self::MOVED_PAGE,
            FetchResult::success(200, self::MOVED_PAGE, $this->page('Original title', 'body')),
        );
        $report = $this->collector($f)->run($this->source(), [self::PAGE, self::MOVED_PAGE], 4_000);

        self::assertSame(1, $report['suppressed'], 'the moved body must match the content-level tombstone');
        self::assertSame($beforeEvents, $this->eventTypes(), 'a move must not publish a new event');
        self::assertSame(0, $this->snapshotCount(), 'the moved body must not be retained');
        self::assertCount(1, $this->rows('monitor_item'), 'the moved URL must not create a second public item');
        $tombstones = $this->rows(CollectorState::SUPPRESSION_TABLE);
        self::assertCount(2, $tombstones, 'the original and moved opaque URL keys share the obligation');
        $rawHash = \App\Monitor\ContentNormalizer::hash($this->page('Original title', 'body'));
        foreach ($tombstones as $tombstone) {
            self::assertNotSame($rawHash, (string) $tombstone['content_fingerprint'], 'the raw content hash is not retained');
            self::assertStringNotContainsString('Original title', implode('|', array_map('strval', $tombstone)));
            self::assertStringNotContainsString('body', implode('|', array_map('strval', $tombstone)));
        }
        self::assertTrue(
            new CollectorState($this->database())->isSuppressed(
                self::SOURCE_KEY,
                \App\Monitor\UrlNormalizer::itemKey(self::MOVED_PAGE),
            ),
            'the opaque new URL key inherits the tombstone',
        );

        // The sibling transition matters too: explicitly releasing that
        // content after the move must restore the original identity, not mint a
        // second item and call it a new appearance.
        $state = new CollectorState($this->database());
        $state->clearSuppression(self::SOURCE_KEY, \App\Monitor\UrlNormalizer::itemKey(self::PAGE), 5_000);
        $recovery = FixturePageFetcher::withPages([self::MOVED_PAGE => $this->page('Original title', 'body')]);
        $this->collector($recovery)->run($this->source(), [self::MOVED_PAGE], 6_000);

        self::assertCount(1, $this->rows('monitor_item'));
        self::assertSame($originalPublicRef, $this->publicRef());
        self::assertSame('Original title', (string) $this->itemRow()['title']);
        self::assertSame(self::MOVED_PAGE, (string) $this->itemRow()['public_url']);
        self::assertSame('became_retainable', $this->eventTypes()[array_key_last($this->eventTypes())]);
        self::assertSame(1, count(array_filter($this->eventTypes(), static fn (string $type): bool => $type === 'appeared')));
    }

    #[Test]
    public function suppressionSurvivesAReloadFromTheDatabase(): void
    {
        $this->seedAndRedact();

        // Drop the kernel and read the state through a brand new connection —
        // suppression must be a persisted fact, not in-process memory.
        $this->kernel = null;
        $state = new CollectorState($this->database());

        self::assertTrue(
            $state->isSuppressed(self::SOURCE_KEY, \App\Monitor\UrlNormalizer::itemKey(self::PAGE)),
            'suppression must be durable across a restart',
        );

        $f = $this->fixture($this->page('Original title', 'body after restart'));
        $this->collector($f)->run($this->source(), [self::PAGE], 5_000);
        $this->assertSuppressed();
    }

    #[Test]
    public function anExplicitlyClearedSuppressionAllowsCollectionAgain(): void
    {
        // The audited maintainer action. Nothing in the collector calls this —
        // asserted separately below — but once a maintainer lifts it, normal
        // collection resumes rather than the item being permanently dead.
        $this->seedAndRedact();
        $itemKey = \App\Monitor\UrlNormalizer::itemKey(self::PAGE);

        new CollectorState($this->database())->clearSuppression(self::SOURCE_KEY, $itemKey, 6_000);

        $f = $this->fixture($this->page('Back in service', 'body'));
        $this->collector($f)->run($this->source(), [self::PAGE], 7_000);

        self::assertFalse(new CollectorState($this->database())->isSuppressed(self::SOURCE_KEY, $itemKey));
        self::assertGreaterThan(0, $this->snapshotCount(), 'collection resumes after an audited clear');
        self::assertSame('Back in service', (string) $this->itemRow()['title']);
        self::assertSame(self::PAGE, (string) $this->itemRow()['public_url']);
        self::assertSame('became_retainable', $this->eventTypes()[array_key_last($this->eventTypes())]);
        self::assertNotContains('content_changed', $this->eventTypes(), 'a cleared tombstone is not proof of a content change');
    }

    #[Test]
    public function theCollectorNeverClearsSuppressionItself(): void
    {
        // Mutation control for the suppression tests: if the collector could
        // lift a suppression, every assertion above would still pass on the
        // first run and silently fail on the second.
        $collectorSource = (string) file_get_contents($this->projectRoot . '/src/Monitor/PublicSiteCollector.php');

        self::assertStringNotContainsString('clearSuppression', $collectorSource);
        self::assertStringNotContainsString('suppressed_at\' => 0', $collectorSource);
    }

    #[Test]
    public function aFailedRedactionLeavesNoSuppressionBehind(): void
    {
        $eventId = $this->seedPublicItem();
        $this->pdo()->exec('ALTER TABLE monitor_item RENAME TO monitor_item_hidden');

        $status = $this->redactCommand()->run(new RecordingCommandIo(), $eventId, 'personal_information', 3_000);
        self::assertSame(1, $status);

        $this->pdo()->exec('ALTER TABLE monitor_item_hidden RENAME TO monitor_item');

        self::assertFalse(
            new CollectorState($this->database())->isSuppressed(self::SOURCE_KEY, \App\Monitor\UrlNormalizer::itemKey(self::PAGE)),
            'a rolled-back redaction must not leave a suppression tombstone',
        );
        self::assertGreaterThan(0, $this->snapshotCount(), 'and the snapshots are restored');
    }

    #[Test]
    public function publicToAbsentTwicePublishesOneRemoval(): void
    {
        $f = $this->fixture($this->page('Water advisory', 'body'));
        $this->collector($f)->run($this->source(), [self::PAGE], 1_000);

        $f->set(self::PAGE, FetchResult::success(404, self::PAGE, ''));
        $this->collector($f)->run($this->source(), [self::PAGE], 2_000);
        self::assertSame(['appeared'], $this->eventTypes(), 'one absent run publishes nothing');

        $this->collector($f)->run($this->source(), [self::PAGE], 3_000);
        self::assertSame(['appeared', 'disappeared'], $this->eventTypes());
    }

    #[Test]
    public function absentToPublicReappears(): void
    {
        $f = $this->fixture($this->page('Water advisory', 'body'));
        $this->collector($f)->run($this->source(), [self::PAGE], 1_000);
        $f->set(self::PAGE, FetchResult::success(404, self::PAGE, ''));
        $this->collector($f)->run($this->source(), [self::PAGE], 2_000);
        $this->collector($f)->run($this->source(), [self::PAGE], 3_000);

        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('Water advisory', 'body')));
        $this->collector($f)->run($this->source(), [self::PAGE], 4_000);

        self::assertSame(['appeared', 'disappeared', 'reappeared'], $this->eventTypes());
        self::assertSame(0, (int) $this->itemRow()['disappeared_at'], 'the stale disappearance is cleared');
    }

    #[Test]
    public function aFailureBetweenAbsenceObservationsDoesNotAdvanceRemoval(): void
    {
        $f = $this->fixture($this->page('Water advisory', 'body'));
        $this->collector($f)->run($this->source(), [self::PAGE], 1_000);

        $f->set(self::PAGE, FetchResult::success(404, self::PAGE, ''));
        $this->collector($f)->run($this->source(), [self::PAGE], 2_000);

        // A transport failure says nothing about whether the page exists.
        $f->set(self::PAGE, FetchResult::failure(self::PAGE, 'timeout'));
        $this->collector($f)->run($this->source(), [self::PAGE], 3_000);

        self::assertSame(['appeared'], $this->eventTypes(), 'a failure must not complete a removal');
    }

    // ------------------------------------------------------------------

    private function driveEveryTransition(): void
    {
        $f = $this->fixture('');
        $c = fn (): array => $this->collector($f)->run($this->source(), [self::PAGE], random_int(1_000, 9_000));

        // Enter the empty-public-ref state first. The invariant test used to
        // start public, so it could pass with every orphan-event defect intact.
        $f->set(self::PAGE, FetchResult::success(401, self::PAGE, ''));
        $c();
        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('Water advisory', 'body')));
        $c();
        $f->set(self::PAGE, FetchResult::success(401, self::PAGE, ''));
        $c();
        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('Back', 'body')));
        $c();
        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->noindexPage()));
        $c();
        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('Back again', 'body')));
        $c();
        $f->set(self::PAGE, FetchResult::success(404, self::PAGE, ''));
        $c();
        $c();
        $f->set(self::PAGE, FetchResult::success(200, self::PAGE, $this->page('Returned', 'body')));
        $c();
    }

    private function seedPublicItem(): string
    {
        $f = $this->fixture($this->page('Original title', 'body'));
        $this->collector($f)->run($this->source(), [self::PAGE], 1_000);

        return (string) $this->rows('monitor_event')[0]['id'];
    }

    private function seedAndRedact(): string
    {
        $eventId = $this->seedPublicItem();
        $io = new RecordingCommandIo();
        self::assertSame(0, $this->redactCommand()->run($io, $eventId, 'personal_information', 3_000), $io->output());

        return $eventId;
    }

    private function assertSuppressed(): void
    {
        self::assertSame(0, $this->snapshotCount(), 'no snapshot may reappear while suppressed');
        self::assertSame('', $this->stateHash(), 'no hash may reappear while suppressed');
        self::assertSame('Redacted at request', (string) $this->itemRow()['title'], 'no title may reappear');
        self::assertSame('', (string) $this->itemRow()['public_url'], 'no locator may reappear');
    }

    private function assertItem(bool $exists, ?string $title = null, ?bool $hashEmpty = null, ?int $snapshots = null): void
    {
        $row = $this->itemRowOrNull();
        if (!$exists) {
            self::assertNull($row, 'no item may exist yet');

            return;
        }

        self::assertNotNull($row, 'an item must exist');
        if ($title !== null) {
            self::assertSame($title, (string) $row['title']);
        }
        if ($hashEmpty !== null) {
            $hashEmpty ? self::assertSame('', $this->stateHash()) : self::assertNotSame('', $this->stateHash());
        }
        if ($snapshots !== null) {
            self::assertSame($snapshots, $this->snapshotCount());
        }

        // The public projection must always be the exact closed set. A deny
        // list can pass while a newly-added sensitive field leaks.
        $repository = new SagamokMonitorRepository($this->manager());
        foreach ($this->manager()->getRepository(MonitorEntityTypes::ITEM)->findBy([]) as $entity) {
            self::assertSame(
                SagamokMonitorRepository::projectedKeys(MonitorEntityTypes::ITEM),
                array_keys($repository->view($entity, 9_999)),
            );
        }
    }

    // ------------------------------------------------------------------

    private function fixture(string $body): FixturePageFetcher
    {
        return FixturePageFetcher::withPages([self::PAGE => $body === '' ? '<html></html>' : $body]);
    }

    private function page(string $title, string $body): string
    {
        return sprintf('<html><head><title>%s</title></head><body><p>%s</p></body></html>', $title, $body);
    }

    private function noindexPage(): string
    {
        return '<html><head><meta name="robots" content="noindex"><title>Hidden</title></head><body>x</body></html>';
    }

    /** @return list<string> */
    private function eventTypes(): array
    {
        $rows = $this->rows('monitor_event');
        usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return array_map(static fn (array $r): string => (string) $r['event_type'], $rows);
    }

    private function publicRef(): string
    {
        return (string) ($this->itemRowOrNull()['public_ref'] ?? '');
    }

    /** @return array<string, mixed> */
    private function itemRow(): array
    {
        $row = $this->itemRowOrNull();
        self::assertNotNull($row, 'an item was expected');

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function itemRowOrNull(): ?array
    {
        $rows = $this->rows('monitor_item');

        return $rows === [] ? null : $rows[0];
    }

    /** @return array<string, mixed> */
    private function eventRow(string $id): array
    {
        foreach ($this->rows('monitor_event') as $row) {
            if ((string) $row['id'] === $id) {
                return $row;
            }
        }
        self::fail('event ' . $id . ' not found');
    }

    private function snapshotCount(): int
    {
        return count($this->rows(CollectorState::SNAPSHOT_TABLE));
    }

    private function stateHash(): string
    {
        $rows = $this->rows(CollectorState::TABLE);

        return $rows === [] ? '' : (string) ($rows[0]['content_hash'] ?? '');
    }

    private function renderedDashboard(): string
    {
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/communities/sagamok/monitor/pages',
            'QUERY_STRING' => '',
            'HTTP_HOST' => 'rhtcircle.ca',
            'SERVER_NAME' => 'rhtcircle.ca',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $this->projectRoot . '/public/index.php',
        ];

        return (string) new HttpKernel($this->projectRoot)->handle()->getContent();
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table): array
    {
        $pdo = $this->pdo();
        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $table . "'")
            ->fetchAll(\PDO::FETCH_ASSOC);
        if ($exists === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $pdo->query('SELECT * FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    private function pdo(): \PDO
    {
        return new \PDO('sqlite:' . $this->databasePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function redactCommand(): MonitorRedactEventCommand
    {
        return new MonitorRedactEventCommand($this->manager(), new CollectorState($this->database()), $this->database());
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
