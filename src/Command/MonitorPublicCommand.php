<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitorSource;
use App\Monitor\CollectorState;
use App\Monitor\MonitorConfiguration;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\PageFetcherInterface;
use App\Monitor\PublicSiteCollector;
use App\Monitor\PublicSiteCrawler;
use App\Monitor\UrlNormalizer;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * `bin/waaseyaa sagamok:monitor-public [--dry-run]` — one collection run over
 * the Sagamok **public website**.
 *
 * The command owns three things the collector deliberately does not:
 *
 *  1. **Idempotent source initialisation.** The `monitor_source` row is created
 *     from configuration if absent and reconciled if the configuration changed,
 *     so a fresh deployment works without anyone remembering to seed a row by
 *     hand — which is what "monitors nothing" looked like before.
 *  2. **Discovery.** The crawl starts at the configured seed pages and follows
 *     public same-origin links, so newly published pages are found rather than
 *     only re-checked.
 *  3. **Failing closed.** Missing configuration, a disabled monitor, or a run
 *     that discovered nothing all exit non-zero rather than reporting a healthy
 *     run over an empty set.
 *
 * The fetcher is injected, so tests drive the whole command against fixtures and
 * no test run can reach the production site.
 *
 * `--dry-run` performs **zero** writes of every kind: no entity, no event, no
 * source-health update, no side-table row, no snapshot, no audit entry, and no
 * DDL — including no source seeding.
 */
final class MonitorPublicCommand
{
    public function __construct(
        private readonly EntityTypeManager $entityTypes,
        private readonly DatabaseInterface $database,
        private readonly PageFetcherInterface $fetcher,
        private readonly MonitorConfiguration $configuration,
    ) {}

    public function run(SymfonyCommandIO $io, bool $dryRun, int $now): int
    {
        if (!$this->configuration->isRunnable()) {
            $io->error(
                'The Sagamok monitor is not configured: set sagamok_monitor.origin_url and at least one '
                . 'seed_path in config/waaseyaa.php. Refusing to run against a default nobody chose.',
            );

            return 1;
        }

        // The kill-switch is unconditional (review finding 11). It previously
        // exempted `--dry-run`, on the reasoning that a dry run writes nothing.
        // But a dry run still *fetches*: it makes real HTTP requests to another
        // Nation's website. The switch that says "we are not monitoring right
        // now" has to mean that, or it does not mean anything.
        //
        // This was masked while the shipped config had `enabled => false` and
        // no source row existed, because the dry run bailed a few lines below.
        // Once the monitor had been enabled once and then turned off, the row
        // persisted and `--dry-run` would have resumed making live requests.
        if (!$this->configuration->enabled) {
            $io->writeln(sprintf(
                'The Sagamok monitor is disabled in configuration (sagamok_monitor.enabled). Nothing was fetched%s.',
                $dryRun ? ' — a dry run still makes real requests, so it is disabled too' : '',
            ));

            return 0;
        }

        $source = $dryRun
            ? $this->existingSource()
            : $this->initialiseSource($now);

        if ($source === null) {
            $io->error('No monitor source is available. A dry run cannot seed one; run once without --dry-run first.');

            return 1;
        }

        if (!(bool) $source->get('enabled')) {
            $io->writeln(sprintf('Source "%s" is disabled; nothing to do.', $this->configuration->sourceKey));

            return 0;
        }

        // --- discovery -------------------------------------------------------
        $discovery = new PublicSiteCrawler($this->fetcher)->discover(
            $this->configuration->seedUrls(),
            $this->configuration->originUrl,
            $this->configuration->maxUrlsPerRun,
            $this->configuration->maxCrawlDepth,
        );

        $io->writeln(sprintf(
            'discovered %d URL(s) from %d seed(s), %d page(s) fetched during discovery%s.',
            count($discovery['urls']),
            count($this->configuration->seedPaths),
            $discovery['fetched'],
            $discovery['truncated'] ? sprintf(' (truncated at the %d ceiling)', $this->configuration->maxUrlsPerRun) : '',
        ));

        if ($discovery['reachable'] === 0) {
            // Every seed was unreachable, so the crawl has no targets. This is
            // a configuration or connectivity failure, not a finding about the
            // Nation's site, and it must not be recorded as a successful check.
            $io->error(
                'Discovery reached no readable page from any configured seed. Nothing was collected and no '
                . 'successful check was recorded: reporting health for a run that read nothing would tell '
                . 'members the site is being watched when it is not.',
            );

            return 1;
        }

        // --- target set: already-tracked URLs UNION newly discovered ---------
        //
        // Discovery alone is not enough. If a publisher removes both a page and
        // every link to it, the page vanishes from the crawl — so the tracked
        // URL would never be requested again and the two-run disappearance rule
        // could never fire. The removal would simply go unreported, which is
        // the opposite of what this dashboard exists to do.
        //
        // Tracked URLs come FIRST in the budget, deterministically, so a growing
        // site can never push existing records out of monitoring.
        $targets = $this->unionTargets($discovery['urls']);

        $io->writeln(sprintf(
            'target set: %d tracked + %d newly discovered = %d URL(s)%s.',
            $targets['tracked_count'],
            $targets['new_count'],
            count($targets['urls']),
            $targets['truncated'] ? sprintf(' (capped at %d; tracked URLs kept first)', $this->configuration->maxUrlsPerRun) : '',
        ));

        // --- collection ------------------------------------------------------
        $collector = new PublicSiteCollector(
            $this->entityTypes->getRepository(MonitorEntityTypes::SOURCE),
            $this->entityTypes->getRepository(MonitorEntityTypes::ITEM),
            $this->entityTypes->getRepository(MonitorEntityTypes::EVENT),
            new CollectorState($this->database),
            $this->fetcher,
        );

        $report = $collector->run($source, $targets['urls'], $now, $dryRun);

        $this->summarise($io, $report);

        if ($report['exit_code'] !== 0) {
            $io->error(
                'The run had no targets to observe. Nothing was recorded as a successful check, because '
                . 'reporting health for a run that fetched nothing would tell members the site is being '
                . 'watched when it is not.',
            );
        }

        return (int) $report['exit_code'];
    }

    /**
     * Previously tracked public URLs, then newly discovered ones, bounded by the
     * per-run ceiling.
     *
     * Ordering is deliberate and deterministic: an item already under
     * observation keeps its place in the budget, so a site that publishes a
     * hundred new pages cannot silently drop existing records out of
     * monitoring. Same-origin is re-checked here because a tracked URL is
     * historical data — the configured origin may have changed since it was
     * recorded.
     *
     * @param list<string> $discovered
     * @return array{urls: list<string>, tracked_count: int, new_count: int, truncated: bool}
     */
    private function unionTargets(array $discovered): array
    {
        $origin = $this->configuration->originUrl;
        $ceiling = $this->configuration->maxUrlsPerRun;

        $urls = [];
        $tracked = 0;

        // The public URL lives on the monitor_item entity, not the side table:
        // the side table holds the opaque identity key, and deliberately no
        // locator. This is the supported read of what we are already watching.
        $items = $this->entityTypes->getRepository(MonitorEntityTypes::ITEM)
            ->findBy(['source_key' => $this->configuration->sourceKey]);

        foreach ($items as $item) {
            $url = (string) $item->get('public_url');
            if ($url === '' || !UrlNormalizer::isSameOrigin($url, $origin)) {
                continue;
            }
            $key = UrlNormalizer::itemKey($url);
            if (!isset($urls[$key]) && count($urls) < $ceiling) {
                $urls[$key] = $url;
                ++$tracked;
            }
        }

        $new = 0;
        $truncated = false;
        foreach ($discovered as $url) {
            $key = UrlNormalizer::itemKey($url);
            if (isset($urls[$key])) {
                continue;
            }
            if (count($urls) >= $ceiling) {
                $truncated = true;
                break;
            }
            $urls[$key] = $url;
            ++$new;
        }

        return [
            'urls' => array_values($urls),
            'tracked_count' => $tracked,
            'new_count' => $new,
            'truncated' => $truncated,
        ];
    }

    /**
     * Create the source row from configuration if absent, reconcile it if the
     * configuration moved. Idempotent: a second call changes nothing.
     */
    private function initialiseSource(int $now): ?MonitorSource
    {
        $repository = $this->entityTypes->getRepository(MonitorEntityTypes::SOURCE);
        $existing = $this->existingSource();

        if ($existing === null) {
            $source = $repository->create([
                'key' => $this->configuration->sourceKey,
                'label' => $this->configuration->label,
                'origin_url' => $this->configuration->originUrl,
                'enabled' => true,
                'health' => 'ok',
                'last_check_started' => 0,
                'last_check_completed' => 0,
                'last_success' => 0,
                'consecutive_failures' => 0,
            ]);
            $repository->save($source, validate: false);

            return $source instanceof MonitorSource ? $source : null;
        }

        // Reconcile label/origin drift, but never re-enable a source an
        // operator disabled: that decision belongs to them, not to config.
        $changed = false;
        if ((string) $existing->get('label') !== $this->configuration->label) {
            $existing->set('label', $this->configuration->label);
            $changed = true;
        }
        if ((string) $existing->get('origin_url') !== $this->configuration->originUrl) {
            $existing->set('origin_url', $this->configuration->originUrl);
            $changed = true;
        }
        if ($changed) {
            $repository->save($existing, validate: false);
        }

        return $existing;
    }

    private function existingSource(): ?MonitorSource
    {
        foreach (
            $this->entityTypes->getRepository(MonitorEntityTypes::SOURCE)
                ->findBy(['key' => $this->configuration->sourceKey]) as $candidate
        ) {
            if ($candidate instanceof MonitorSource) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function summarise(SymfonyCommandIO $io, array $report): void
    {
        $types = array_count_values(array_column($report['events'], 'type'));

        if ($report['dry_run']) {
            $io->writeln('DRY RUN — nothing was written.');
        }
        if ($report['baseline_run']) {
            $io->writeln('Baseline run: these items were first observed by this monitor, not newly published.');
        }

        $io->writeln(sprintf(
            'new %d; changed %d; unchanged %d; absent %d; removed %d; gated %d; not retained %d; failed %d.',
            $types['appeared'] ?? 0,
            $types['content_changed'] ?? 0,
            max(0, $report['observed'] - (($types['appeared'] ?? 0) + ($types['content_changed'] ?? 0) + ($types['reappeared'] ?? 0) + ($types['became_retainable'] ?? 0))),
            $types['absent_pending'] ?? 0,
            $types['disappeared'] ?? 0,
            $report['gated'],
            $report['not_retained'],
            $report['fetch_failures'],
        ));

        if (($types['reappeared'] ?? 0) > 0) {
            $io->writeln(sprintf('returned %d.', $types['reappeared']));
        }
        if (($types['became_retainable'] ?? 0) > 0) {
            $io->writeln(sprintf('became retainable again %d.', $types['became_retainable']));
        }
        if ($report['skipped_off_origin'] > 0) {
            $io->writeln(sprintf('skipped %d off-origin URL(s).', $report['skipped_off_origin']));
        }
        if ($report['indeterminate'] !== []) {
            // Named explicitly so an operator can tell "the server could not
            // answer" from "the page is gone". Only the latter is a removal.
            $io->writeln(sprintf(
                'indeterminate response(s): %s — counted against source health, never as a removal.',
                implode(', ', array_unique(array_map('strval', $report['indeterminate']))),
            ));
        }
        if ($report['truncated_at_limit']) {
            $io->writeln(sprintf('NOTE: the URL list exceeded the %d-per-run ceiling and was truncated.', PublicSiteCollector::MAX_URLS_PER_RUN));
        }
        if ($report['not_retained'] > 0) {
            $io->writeln('Some pages asked not to be retained (noindex); they remain publicly reachable.');
        }

        $io->writeln('source health: ' . $report['health']);
    }
}
