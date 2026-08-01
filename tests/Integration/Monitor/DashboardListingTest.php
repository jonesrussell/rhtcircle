<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Access\MonitorDashboardAccessPolicy;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\SagamokMonitorRepository;
use App\Provider\SagamokMonitorServiceProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ListingResolver;

/**
 * Review finding 3: the dashboard must be backed by the **Listing pipeline**,
 * not by repository scans beside seven unused definitions.
 *
 * The definitions carry the access ops, the sorts, the page sizes and the cache
 * tags. A controller that queried entities directly bypassed all of it — the
 * `monitor.dashboard_read` gate included — and left the Listing package as
 * decoration. These tests assert the data path, not just the rendered output,
 * because a page can look right while being produced the wrong way.
 */
final class DashboardListingTest extends TestCase
{
    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?HttpKernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-listing-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=listing-test-secret');
        putenv('WAASEYAA_DEV_FALLBACK_ACCOUNT=false');

        $this->runCli('db:init');
        $this->seed();
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

    public function testEveryRegisteredListingHasAProductionConsumer(): void
    {
        // A definition nobody resolves is dead weight that still has to be
        // reviewed and maintained. Each one must be reachable from real code.
        $consumed = SagamokMonitorRepository::consumedListingIds();

        $registered = [];
        foreach (new SagamokMonitorServiceProvider()->listings() as $listing) {
            $registered[] = $listing->id;
        }

        sort($registered);
        $sortedConsumed = $consumed;
        sort($sortedConsumed);

        self::assertSame(
            $registered,
            $sortedConsumed,
            'every registered listing must have a consumer, and every consumer a registered listing',
        );
    }

    public function testTheDashboardResolvesThroughTheListingResolver(): void
    {
        $repository = $this->repository();

        $result = $repository->resolveListing(SagamokMonitorServiceProvider::LISTING_TIMELINE, 1, 1_000_000);

        self::assertArrayHasKey('rows', $result);
        self::assertArrayHasKey('pagination', $result);
        self::assertNotEmpty($result['rows'], 'the seeded events must come back through the resolver');
    }

    public function testTheResolverAppliesTheDefinitionSortRatherThanControllerSorting(): void
    {
        // The timeline is declared `Sort::desc('observed_at')`. If the rows come
        // back newest-first without the controller touching them, the sort is
        // genuinely coming from the definition.
        $rows = $this->repository()->resolveListing(SagamokMonitorServiceProvider::LISTING_TIMELINE, 1, 0)['rows'];

        $observed = array_map(static fn (array $r): int => (int) $r['observed_at'], $rows);
        $sorted = $observed;
        rsort($sorted);

        self::assertSame($sorted, $observed, 'ordering comes from the ListingDefinition');
    }

    public function testPaginationTotalsComeFromTheResolver(): void
    {
        $pagination = $this->repository()->resolveListing(SagamokMonitorServiceProvider::LISTING_TIMELINE, 1, 0)['pagination'];

        self::assertSame(1, $pagination['page']);
        self::assertSame(50, $pagination['page_size'], 'the declared page size, not a controller constant');
        self::assertSame(6, $pagination['total_rows'], 'a real total, not a count of the sliced array');
        self::assertFalse($pagination['has_prev']);
    }

    public function testTheOpenIssuesListingAppliesItsDeclaredFilter(): void
    {
        // Declared `Filter::in('issue_state', ['open','awaiting_response','partly_answered'])`.
        // The resolved rows must honour it without the controller filtering.
        $rows = $this->repository()->resolveListing(SagamokMonitorServiceProvider::LISTING_ISSUES_OPEN, 1, 0)['rows'];

        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertContains((string) $row['issue_state'], ['open', 'awaiting_response', 'partly_answered']);
        }
        self::assertNotContains('resolved', array_column($rows, 'issue_state'));
    }

    public function testEveryListingRequiresTheBespokeDashboardAbility(): void
    {
        // The gate that makes these listings safe: they ask for
        // `monitor.dashboard_read` and nothing else. Asking for `view` would
        // inherit whatever the entity gate grants, which is the leak the whole
        // design avoids.
        foreach (new SagamokMonitorServiceProvider()->listings() as $listing) {
            self::assertSame([MonitorDashboardAccessPolicy::ABILITY], $listing->accessOps, $listing->id);
        }

        // And that ability is granted by the monitor policy alone.
        $policy = new MonitorDashboardAccessPolicy();
        $entity = $this->kernel()->getEntityTypeManager()
            ->getRepository(MonitorEntityTypes::ITEM)
            ->create(['source_key' => 'sagamok_public_site', 'public_ref' => 'p-1']);
        $anonymous = new \Waaseyaa\User\AnonymousUser([]);

        self::assertTrue($policy->access($entity, MonitorDashboardAccessPolicy::ABILITY, $anonymous)->isAllowed());
        self::assertFalse($policy->access($entity, 'view', $anonymous)->isAllowed());
    }

    public function testTheRenderedDashboardCarriesResolverBackedRows(): void
    {
        $html = (string) $this->request('/communities/sagamok/monitor')->getContent();

        // Content that only exists because a listing resolved it.
        self::assertStringContainsString('Records request awaiting response', $html);
        self::assertStringContainsString('Monitoring status', $html);
    }

    public function testTheRepositoryRefusesToResolveWithoutTheListingPipeline(): void
    {
        // Fail loudly rather than silently falling back to a table scan, which
        // is how the pipeline became decoration in the first place.
        $bare = new SagamokMonitorRepository($this->kernel()->getEntityTypeManager());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Listing pipeline/');

        $bare->resolveListing(SagamokMonitorServiceProvider::LISTING_TIMELINE);
    }

    // ------------------------------------------------------------------

    /**
     * Built from the kernel's own provider wiring, not hand-assembled: the
     * point of these tests is that the production data path goes through the
     * resolver, so the resolver under test must be the one the app uses.
     */
    private function repository(): SagamokMonitorRepository
    {
        $kernel = $this->kernel();

        $registry = null;
        $resolver = null;
        foreach ($kernel->getProviders() as $provider) {
            $registry ??= $this->resolveFrom($provider, ListingDefinitionRegistry::class);
            $resolver ??= $this->resolveFrom($provider, ListingResolver::class);
        }

        self::assertInstanceOf(ListingDefinitionRegistry::class, $registry, 'the kernel must bind a listing registry');
        self::assertInstanceOf(ListingResolver::class, $resolver, 'the kernel must bind a listing resolver');

        return new SagamokMonitorRepository($kernel->getEntityTypeManager(), $registry, $resolver);
    }

    private function resolveFrom(object $provider, string $class): ?object
    {
        if (!method_exists($provider, 'resolve')) {
            return null;
        }

        try {
            // No setAccessible(): redundant since PHP 8.1 and deprecated.
            $service = new \ReflectionMethod($provider, 'resolve')->invoke($provider, $class);
        } catch (\Throwable) {
            return null;
        }

        return $service instanceof $class ? $service : null;
    }

    private function seed(): void
    {
        $manager = $this->kernel()->getEntityTypeManager();

        $sources = $manager->getRepository(MonitorEntityTypes::SOURCE);
        $source = $sources->create([
            'key' => 'sagamok_public_site',
            'label' => 'Sagamok public website',
            'origin_url' => 'https://www.sagamokanishnawbek.test/',
            'enabled' => true,
            'health' => 'ok',
            'last_check_completed' => 1_000_000,
            'last_success' => 1_000_000,
        ]);
        $sources->save($source, validate: false);

        $events = $manager->getRepository(MonitorEntityTypes::EVENT);
        foreach ([100, 500, 300, 600, 200, 400] as $i => $observedAt) {
            $event = $events->create([
                'source_key' => 'sagamok_public_site',
                'item_public_ref' => 'sagamok_public_site-000' . ($i + 1),
                'event_type' => 'content_changed',
                'observed_at' => $observedAt,
                'evidence_kind' => 'direct_fetch',
                'evidence_captured_at' => $observedAt,
                'redacted_at' => 0,
            ]);
            $events->save($event, validate: false);
        }

        $issues = $manager->getRepository(MonitorEntityTypes::ISSUE);
        foreach ([['records-request', 'Records request awaiting response', 'open'], ['closed-one', 'Already answered', 'resolved']] as [$slug, $title, $state]) {
            $issue = $issues->create([
                'slug' => $slug,
                'title' => $title,
                'issue_state' => $state,
                'opened_at' => 900,
                'summary' => 'Seeded for the listing test.',
            ]);
            $issues->save($issue, validate: false);
        }
    }

    private function request(string $uri): \Symfony\Component\HttpFoundation\Response
    {
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'rhtcircle.ca',
            'SERVER_NAME' => 'rhtcircle.ca',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $this->projectRoot . '/public/index.php',
        ];

        return new HttpKernel($this->projectRoot)->handle();
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
