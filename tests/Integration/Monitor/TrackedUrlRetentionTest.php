<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Command\MonitorPublicCommand;
use App\Monitor\FetchResult;
use App\Monitor\FixturePageFetcher;
use App\Monitor\MonitorConfiguration;
use App\Monitor\MonitorEntityTypes;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Review finding 2: a page whose *link* is removed must still be checked.
 *
 * The command previously collected only URLs rediscovered by the present
 * crawl. If a publisher removed a page **and** its navigation link — the
 * ordinary way a document disappears from a site — the tracked URL fell out of
 * the target set and was never requested again. The two-run disappearance rule
 * could never fire, so the removal simply went unreported. That is the opposite
 * of what this dashboard exists to do.
 *
 * Tracked URLs are now unioned with newly discovered ones, tracked first in the
 * per-run budget so existing records cannot be pushed out by a site that
 * publishes a lot of new pages.
 */
final class TrackedUrlRetentionTest extends TestCase
{
    private const ORIGIN = 'https://www.sagamokanishnawbek.test';
    private const SEED = self::ORIGIN . '/';
    private const NOTICE = self::ORIGIN . '/notices/water-advisory';

    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?HttpKernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-tracked-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=tracked-test-secret');
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

    public function testAPageWhoseLinkIsRemovedIsStillCheckedAndReportedGone(): void
    {
        // --- baseline: the seed links to the notice, both are tracked --------
        $fetcher = FixturePageFetcher::withPages([
            self::SEED => $this->seedLinkingToNotice(),
            self::NOTICE => $this->notice('The advisory remains in effect.'),
        ]);

        self::assertSame(0, $this->command($fetcher)->run($this->io(), false, 1_000));
        self::assertContains(self::NOTICE, $this->trackedUrls(), 'the linked notice is now tracked');

        // --- the publisher removes the page AND the link ---------------------
        $fetcher->set(self::SEED, FetchResult::success(200, self::SEED, $this->seedWithoutNoticeLink()));
        $fetcher->set(self::NOTICE, FetchResult::success(404, self::NOTICE, ''));

        // First run after removal: pending absence, nothing published.
        $io = $this->io();
        self::assertSame(0, $this->command($fetcher)->run($io, false, 2_000));
        self::assertSame(
            0,
            $this->countEventsOfType('disappeared'),
            'one absent observation must not publish a removal',
        );
        self::assertStringContainsString('absent 1', $io->output());

        // The URL is still being requested even though nothing links to it.
        self::assertContains(
            'https://www.sagamokanishnawbek.test/notices/water-advisory',
            $fetcher->requestedUrls(),
            'a tracked URL must keep being checked after its link is removed',
        );

        // --- second consecutive absent run: exactly one disappearance -------
        self::assertSame(0, $this->command($fetcher)->run($this->io(), false, 3_000));
        self::assertSame(1, $this->countEventsOfType('disappeared'));

        // --- and it is not repeated -----------------------------------------
        foreach ([4_000, 5_000, 6_000] as $now) {
            $this->command($fetcher)->run($this->io(), false, $now);
        }
        self::assertSame(1, $this->countEventsOfType('disappeared'), 'a removal is announced once');
    }

    public function testTrackedUrlsAreReportedInTheRunSummary(): void
    {
        $fetcher = FixturePageFetcher::withPages([
            self::SEED => $this->seedLinkingToNotice(),
            self::NOTICE => $this->notice('v1'),
        ]);
        $this->command($fetcher)->run($this->io(), false, 1_000);

        $io = $this->io();
        $this->command($fetcher)->run($io, false, 2_000);

        // Two tracked (seed + notice), nothing new the second time round.
        self::assertMatchesRegularExpression('/target set: 2 tracked \+ 0 newly discovered/', $io->output());
    }

    public function testTrackedUrlsKeepTheirPlaceInTheBudgetAheadOfNewOnes(): void
    {
        // A site that publishes a lot of new pages must not push existing
        // records out of monitoring: the budget is filled tracked-first.
        $pages = [self::SEED => $this->seedLinkingToNotice(), self::NOTICE => $this->notice('v1')];
        $fetcher = FixturePageFetcher::withPages($pages);
        $this->command($fetcher)->run($this->io(), false, 1_000);

        // Now the seed links to many new pages, and the ceiling is tiny.
        $links = '';
        for ($i = 1; $i <= 20; ++$i) {
            $links .= sprintf('<a href="/new/%d">n%d</a>', $i, $i);
            $fetcher->set(self::ORIGIN . '/new/' . $i, FetchResult::success(200, self::ORIGIN . '/new/' . $i, $this->notice('new ' . $i)));
        }
        $fetcher->set(self::SEED, FetchResult::success(200, self::SEED, '<html><head><title>Home</title></head><body>' . $links . '</body></html>'));

        $io = $this->io();
        $this->command($fetcher, maxUrls: 3)->run($io, false, 2_000);

        // The two already-tracked URLs survive the cap.
        self::assertMatchesRegularExpression('/target set: 2 tracked \+ 1 newly discovered/', $io->output());
        self::assertStringContainsString('tracked URLs kept first', $io->output());
    }

    public function testAnOffOriginTrackedUrlIsNotRequested(): void
    {
        // Tracked URLs are historical data: the configured origin may have moved
        // since they were recorded, so same-origin is re-checked here too.
        $items = $this->manager()->getRepository(MonitorEntityTypes::ITEM);
        $stale = $items->create([
            'source_key' => 'sagamok_public_site',
            'public_ref' => 'sagamok_public_site-9999',
            'title' => 'Stale',
            'public_url' => 'https://example.org/elsewhere',
            'first_seen' => 1,
            'last_seen' => 1,
        ]);
        $items->save($stale, validate: false);

        $fetcher = FixturePageFetcher::withPages([self::SEED => $this->seedLinkingToNotice(), self::NOTICE => $this->notice('v1')]);
        $this->command($fetcher)->run($this->io(), false, 1_000);

        foreach ($fetcher->requestedUrls() as $requested) {
            self::assertStringNotContainsString('example.org', $requested);
        }
    }

    // ------------------------------------------------------------------

    private function seedLinkingToNotice(): string
    {
        return '<html><head><title>Home</title></head><body>'
            . '<a href="/notices/water-advisory">Water advisory</a>'
            . '</body></html>';
    }

    private function seedWithoutNoticeLink(): string
    {
        return '<html><head><title>Home</title></head><body><p>Nothing here now.</p></body></html>';
    }

    private function notice(string $body): string
    {
        return sprintf('<html><head><title>Water advisory</title></head><body><p>%s</p></body></html>', $body);
    }

    /** @return list<string> */
    private function trackedUrls(): array
    {
        $urls = [];
        foreach ($this->manager()->getRepository(MonitorEntityTypes::ITEM)->findBy([]) as $item) {
            $urls[] = (string) $item->get('public_url');
        }

        return $urls;
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

    private function command(FixturePageFetcher $fetcher, int $maxUrls = 300): MonitorPublicCommand
    {
        return new MonitorPublicCommand(
            $this->manager(),
            $this->kernel()->getDatabase(),
            $fetcher,
            MonitorConfiguration::fromConfig(['sagamok_monitor' => [
                'enabled' => true,
                'source_key' => 'sagamok_public_site',
                'label' => 'Sagamok public website',
                'origin_url' => self::ORIGIN,
                'seed_paths' => ['/'],
                'max_urls_per_run' => $maxUrls,
                'max_crawl_depth' => 2,
            ]]),
        );
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
