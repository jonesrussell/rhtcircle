<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitorSource;
use App\Monitor\CollectorState;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\PageFetcherInterface;
use App\Monitor\PublicSiteCollector;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * `bin/waaseyaa sagamok:monitor-public [--dry-run]` — one collection run over
 * the Sagamok **public website**.
 *
 * The fetcher is injected, so tests drive the whole command against fixtures
 * and no test run can reach the production site.
 *
 * `--dry-run` performs **zero** writes of every kind: no entity, no event, no
 * source-health update, no side-table row, no snapshot, no audit entry, and no
 * DDL. That last one is easy to overlook — creating the side table would make a
 * dry run write-free "except for schema", which is not write-free — so the side
 * table's read paths tolerate its absence rather than the command creating it.
 */
final class MonitorPublicCommand
{
    public function __construct(
        private readonly EntityTypeManager $entityTypes,
        private readonly DatabaseInterface $database,
        private readonly PageFetcherInterface $fetcher,
    ) {}

    /**
     * @param list<string> $urls Same-origin public URLs to observe.
     */
    public function run(SymfonyCommandIO $io, array $urls, bool $dryRun, int $now): int
    {
        $sources = $this->entityTypes->getRepository(MonitorEntityTypes::SOURCE);

        $source = null;
        foreach ($sources->findBy(['key' => 'sagamok_public_site']) as $candidate) {
            if ($candidate instanceof MonitorSource) {
                $source = $candidate;
                break;
            }
        }

        if ($source === null) {
            $io->error('No monitor source "sagamok_public_site" is registered. Seed it before running the collector.');

            return 1;
        }

        if (!(bool) $source->get('enabled')) {
            $io->writeln('Source "sagamok_public_site" is disabled; nothing to do.');

            return 0;
        }

        $collector = new PublicSiteCollector(
            $sources,
            $this->entityTypes->getRepository(MonitorEntityTypes::ITEM),
            $this->entityTypes->getRepository(MonitorEntityTypes::EVENT),
            new CollectorState($this->database),
            $this->fetcher,
        );

        $report = $collector->run($source, $urls, $now, $dryRun);

        $this->summarise($io, $report);

        return 0;
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
            // Observed minus those that produced an event is the unchanged set.
            max(0, $report['observed'] - (($types['appeared'] ?? 0) + ($types['content_changed'] ?? 0) + ($types['reappeared'] ?? 0))),
            $types['absent_pending'] ?? 0,
            $types['disappeared'] ?? 0,
            $report['gated'],
            $report['not_retained'],
            $report['fetch_failures'],
        ));

        if (($types['reappeared'] ?? 0) > 0) {
            $io->writeln(sprintf('returned %d.', $types['reappeared']));
        }
        if ($report['skipped_off_origin'] > 0) {
            $io->writeln(sprintf('skipped %d off-origin URL(s).', $report['skipped_off_origin']));
        }
        if ($report['truncated_at_limit']) {
            $io->writeln(sprintf(
                'NOTE: the URL list exceeded the %d-per-run ceiling and was truncated.',
                PublicSiteCollector::MAX_URLS_PER_RUN,
            ));
        }
        if ($report['not_retained'] > 0) {
            // Worded so it is never mistaken for an access restriction.
            $io->writeln('Some pages asked not to be retained (noindex); they remain publicly reachable.');
        }

        $io->writeln('source health: ' . $report['health']);
    }
}
