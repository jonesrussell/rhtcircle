<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Monitor\MonitorEntityTypes;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Review finding 1: pagination must actually reach the rows.
 *
 * `resolveListing()` took a `$page` argument it could not honour.
 * `ListingResolver` reads `?page=` from the request, and `RequestContext` is
 * per-request and `final readonly` — so **every listing resolved during one
 * request shares one page number**. On a dashboard resolving seven listings,
 * `?page=2` paged all of them together and emptied the small sections.
 *
 * The design that follows from that constraint, without needing anything new
 * from the framework: **exactly one paginated listing per route.**
 *
 *  - the dashboard is the canonical un-paged view and redirects `?page=` away,
 *    so no section can be emptied;
 *  - the two listings that grow have their own routes, where each is the only
 *    listing resolved and `?page=` addresses precisely it.
 *
 * These tests seed **more than one page** of rows and navigate.
 */
final class DashboardPaginationTest extends TestCase
{
    /** `LISTING_TIMELINE` declares pageSize 50, so 120 rows is three pages. */
    private const EVENT_COUNT = 120;
    private const PAGE_SIZE = 50;

    /** `LISTING_ITEMS` declares pageSize 25, so 30 rows is two pages. */
    private const ITEM_COUNT = 30;

    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?HttpKernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-pager-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=pager-test-secret');
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

    public function testTheDashboardRedirectsAPageParameterAwayRatherThanEmptyingSections(): void
    {
        // The failure this prevents: `?page=2` on a seven-listing page would
        // advance every listing, and the sections with fewer than one page of
        // rows would render empty for no reason a reader could understand.
        $response = $this->request('/communities/sagamok/monitor', ['page' => '2']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/communities/sagamok/monitor', $response->headers->get('Location'));
    }

    public function testTheDashboardItselfShowsEverySectionPopulated(): void
    {
        $html = (string) $this->request('/communities/sagamok/monitor')->getContent();

        foreach ([
            'Monitoring status',
            'Recent changes',
            'Questions awaiting an answer',
            'Official updates',
            'Members portal',
        ] as $section) {
            self::assertStringContainsString($section, $html);
        }

        // The bounded preview names its own limit and links to the full view.
        self::assertStringContainsString('See every recorded change', $html);
        self::assertStringContainsString('/communities/sagamok/monitor/changes', $html);
    }

    public function testTheChangesRouteIsPagedAndReachesEveryRow(): void
    {
        $seen = [];
        $page = 1;

        do {
            $html = (string) $this->request('/communities/sagamok/monitor/changes', $page > 1 ? ['page' => (string) $page] : [])->getContent();

            preg_match_all('/data-marker-(\d+)/', $html, $matches);
            foreach ($matches[1] as $marker) {
                $seen[(int) $marker] = true;
            }

            $hasNext = str_contains($html, 'rel="next"');
            ++$page;
        } while ($hasNext && $page <= 10);

        self::assertCount(
            self::EVENT_COUNT,
            $seen,
            'navigating the pager must reach every row, not just the first page',
        );
    }

    public function testTheChangesRouteReportsHonestPaginationTotals(): void
    {
        $html = (string) $this->request('/communities/sagamok/monitor/changes')->getContent();

        $expectedPages = (int) ceil(self::EVENT_COUNT / self::PAGE_SIZE);

        self::assertStringContainsString('Page 1 of ' . $expectedPages, $html);
        self::assertStringContainsString(self::EVENT_COUNT . ' row(s)', $html);
        self::assertStringContainsString('rel="next"', $html);
        self::assertStringNotContainsString('rel="prev"', $html, 'page 1 has no previous');
    }

    public function testTheSecondPageShowsDifferentRowsAndOffersBothDirections(): void
    {
        $first = (string) $this->request('/communities/sagamok/monitor/changes')->getContent();
        $second = (string) $this->request('/communities/sagamok/monitor/changes', ['page' => '2'])->getContent();

        preg_match_all('/data-marker-(\d+)/', $first, $a);
        preg_match_all('/data-marker-(\d+)/', $second, $b);

        self::assertNotEmpty($a[1]);
        self::assertNotEmpty($b[1]);
        self::assertSame([], array_intersect($a[1], $b[1]), 'page 2 must not repeat page 1');

        self::assertStringContainsString('Page 2 of', $second);
        self::assertStringContainsString('rel="prev"', $second);
    }

    public function testThePagesRouteIsIndependentlyPaged(): void
    {
        // This test used to issue ONE request with no `page` parameter and
        // assert two string literals the controller emits unconditionally —
        // while `seed()` created 3 items against `pageSize: 25`, so the pager
        // never rendered at all. No pagination defect on this route was
        // detectable by it.
        //
        // It now walks the pager over more than one page of items and asserts
        // the rows differ, which is the claim its name makes.
        $first = (string) $this->request('/communities/sagamok/monitor/pages')->getContent();
        $second = (string) $this->request('/communities/sagamok/monitor/pages', ['page' => '2'])->getContent();

        preg_match_all('/data-item-(\d+)/', $first, $a);
        preg_match_all('/data-item-(\d+)/', $second, $b);

        self::assertNotEmpty($a[1], 'page 1 must list items');
        self::assertNotEmpty($b[1], 'page 2 must list items');
        self::assertSame([], array_intersect($a[1], $b[1]), 'page 2 must not repeat page 1');
        self::assertStringContainsString('rel="next"', $first, 'more than one page must exist');
        self::assertStringContainsString('Page 2 of', $second);
    }

    public function testPagingOneRouteDoesNotMoveTheOther(): void
    {
        // The independence claim itself, which nothing previously asserted.
        // Each route resolves exactly one listing, so `?page=` addresses that
        // listing and nothing else — the design constraint that follows from
        // RequestContext being per-request.
        $changesPage2 = (string) $this->request('/communities/sagamok/monitor/changes', ['page' => '2'])->getContent();
        $pagesPage1 = (string) $this->request('/communities/sagamok/monitor/pages')->getContent();

        self::assertStringContainsString('Page 2 of', $changesPage2);
        self::assertStringContainsString('Page 1 of', $pagesPage1, 'the pages route is unaffected by the changes route');
    }

    public function testAnOutOfRangePageClampsRatherThanErroring(): void
    {
        $response = $this->request('/communities/sagamok/monitor/changes', ['page' => '999']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Page ', (string) $response->getContent());
    }

    // ------------------------------------------------------------------

    private function seed(): void
    {
        $manager = $this->manager();

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

        // More than one page of events, each carrying a unique marker in a
        // field that reaches the page, so navigation can be proven exhaustive.
        $events = $manager->getRepository(MonitorEntityTypes::EVENT);
        for ($i = 1; $i <= self::EVENT_COUNT; ++$i) {
            $event = $events->create([
                'source_key' => 'sagamok_public_site',
                'item_public_ref' => 'sagamok_public_site-0001',
                'event_type' => 'content_changed',
                'observed_at' => 1_000_000 + $i,
                'evidence_kind' => 'data-marker-' . $i,
                'evidence_captured_at' => 1_000_000 + $i,
                'redacted_at' => 0,
            ]);
            $events->save($event, validate: false);
        }

        // More than ONE page of items. `LISTING_ITEMS` declares pageSize 25, so
        // the previous three rows meant `total_pages` was 1 and the pager block
        // never rendered — which is why the pages-route test could not observe
        // pagination at all. Each title carries a unique marker that reaches
        // the rendered page, so navigation can be proven exhaustive the same
        // way the events timeline is.
        $items = $manager->getRepository(MonitorEntityTypes::ITEM);
        for ($n = 1; $n <= self::ITEM_COUNT; ++$n) {
            $item = $items->create([
                'source_key' => 'sagamok_public_site',
                'public_ref' => sprintf('sagamok_public_site-%04d', $n),
                'title' => 'data-item-' . $n,
                'public_url' => 'https://www.sagamokanishnawbek.test/p/' . $n,
                'change_status' => 'changed',
                'first_seen' => 1,
                // Descending `last_seen` gives a stable, distinct page order.
                'last_seen' => 1_000_000 + $n,
                'changed_at' => 1_000_000 + $n,
            ]);
            $items->save($item, validate: false);
        }

        $issues = $manager->getRepository(MonitorEntityTypes::ISSUE);
        $issue = $issues->create([
            'slug' => 'records-request',
            'title' => 'Records request awaiting response',
            'issue_state' => 'open',
            'opened_at' => 900,
            'summary' => 'Seeded for the pagination test.',
        ]);
        $issues->save($issue, validate: false);

        $updates = $manager->getRepository(MonitorEntityTypes::OFFICIAL_UPDATE);
        $update = $updates->create([
            'issue_slug' => 'records-request',
            'published_at' => 950,
            'source_label' => 'Council minutes',
            'summary' => 'Acknowledged.',
        ]);
        $updates->save($update, validate: false);

        $portal = $manager->getRepository(MonitorEntityTypes::PORTAL_ACCESS_STATE);
        $state = $portal->create([
            'state' => 'access_controlled',
            'verified_on' => 'July 2026',
            'statement' => 'The members portal is access-controlled.',
            'verified_by_role' => 'member reviewer',
        ]);
        $portal->save($state, validate: false);
    }

    /**
     * @param array<string, string> $query
     */
    private function request(string $uri, array $query = []): \Symfony\Component\HttpFoundation\Response
    {
        $_GET = $query;
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri . ($query === [] ? '' : '?' . http_build_query($query)),
            'QUERY_STRING' => http_build_query($query),
            'HTTP_HOST' => 'rhtcircle.ca',
            'SERVER_NAME' => 'rhtcircle.ca',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $this->projectRoot . '/public/index.php',
        ];

        return new HttpKernel($this->projectRoot)->handle();
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
