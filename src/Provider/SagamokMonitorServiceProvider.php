<?php

declare(strict_types=1);

namespace App\Provider;

use App\Command\MonitorPublicCommand;
use App\Command\MonitorRedactEventCommand;
use App\Command\MonitorTriageCommand;
use App\Monitor\CollectorState;
use App\Monitor\HttpPageFetcher;
use App\Monitor\MonitorConfiguration;
use App\Monitor\MonitorEntityTypes;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\HasListingsInterface;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\Sort;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Security\CliFieldReadCapabilityIssuer;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * The Sagamok public-website monitoring dashboard (docs/specs/sagamok-monitoring-dashboard.md).
 *
 * Registers the seven monitor entity types and the seven listings that feed the
 * dashboard. Three registration facts are load-bearing for the exposure
 * boundary and are asserted declaratively by NoAutoExposureTest:
 *
 *   1. no type sets `api: true`, so no JSON:API route is generated;
 *   2. no type sets `group: 'content'`, so the kernel's unconditional
 *      PublishedContentAccessPolicy never grants anonymous `view` — that flag
 *      is an affirmative grant that would open MCP, SSR, GraphQL and Discovery
 *      simultaneously;
 *   3. no type declares a `status` field, so WorkflowVisibility::isEntityPublic()
 *      can never be true and the Discovery routes stay closed even if a future
 *      policy changes.
 *
 * Every listing sets `accessOps: [MonitorDashboardAccessPolicy::ABILITY]`, the
 * bespoke ability that only these listings ask for. See the policy class for
 * why `view` is deliberately never granted.
 */
final class SagamokMonitorServiceProvider extends ServiceProvider implements HasListingsInterface, ProvidesConsoleCommandsInterface
{
    public const string LISTING_SOURCES = 'sagamok_monitor_sources';
    public const string LISTING_ITEMS = 'sagamok_monitor_items';
    public const string LISTING_CHANGES = 'sagamok_monitor_changes';
    public const string LISTING_TIMELINE = 'sagamok_monitor_timeline';
    public const string LISTING_ISSUES_OPEN = 'sagamok_monitor_issues_open';
    public const string LISTING_ISSUES_RESOLVED = 'sagamok_monitor_issues_resolved';
    public const string LISTING_UPDATES = 'sagamok_monitor_updates';

    /** The one monitored source. Public website only; there is no portal source. */
    public const string SOURCE_KEY = 'sagamok_public_site';

    public function register(): void
    {
        // Deliberately: no `api:` argument (defaults false), no `group:`
        // argument (defaults null, NOT 'content'). Both omissions are the
        // boundary. See the class docblock.
        foreach (MonitorEntityTypes::CLASSES as $class) {
            $this->entityType(EntityType::fromClass($class));
        }
    }

    /**
     * @return list<ListingDefinition>
     */
    public function listings(): array
    {
        // The bespoke ability every monitor listing asks for. Nothing else in
        // the app or the framework requests it, so granting it opens nothing
        // beyond these listings.
        $ops = [\App\Access\MonitorDashboardAccessPolicy::ABILITY];

        return [
            new ListingDefinition(
                id: self::LISTING_SOURCES,
                entityType: MonitorEntityTypes::SOURCE,
                filters: [Filter::eq('enabled', true)],
                sorts: [Sort::asc('key')],
                pageSize: 25,
                accessOps: $ops,
            ),
            new ListingDefinition(
                id: self::LISTING_ITEMS,
                entityType: MonitorEntityTypes::ITEM,
                filters: [Filter::eq('source_key', self::SOURCE_KEY)],
                sorts: [Sort::desc('last_seen')],
                pageSize: 25,
                accessOps: $ops,
            ),
            new ListingDefinition(
                id: self::LISTING_CHANGES,
                entityType: MonitorEntityTypes::ITEM,
                filters: [Filter::in('change_status', ['new', 'changed', 'disappeared', 'reappeared'])],
                sorts: [Sort::desc('changed_at')],
                pageSize: 25,
                accessOps: $ops,
            ),
            // Redacted events are filtered out of every projection; the row is
            // retained as a stub so the log cannot silently develop holes.
            new ListingDefinition(
                id: self::LISTING_TIMELINE,
                entityType: MonitorEntityTypes::EVENT,
                filters: [Filter::eq('redacted_at', 0)],
                sorts: [Sort::desc('observed_at')],
                pageSize: 50,
                accessOps: $ops,
            ),
            new ListingDefinition(
                id: self::LISTING_ISSUES_OPEN,
                entityType: MonitorEntityTypes::ISSUE,
                filters: [Filter::in('issue_state', ['open', 'awaiting_response', 'partly_answered'])],
                // Sorted by opened_at only. Never by `severity`: that is an
                // Internal editorial judgement, and a public list ordered by it
                // beside a public date composes into an escalating countdown
                // clock against an office.
                sorts: [Sort::desc('opened_at')],
                pageSize: 25,
                accessOps: $ops,
            ),
            new ListingDefinition(
                id: self::LISTING_ISSUES_RESOLVED,
                entityType: MonitorEntityTypes::ISSUE,
                filters: [Filter::eq('issue_state', 'resolved')],
                sorts: [Sort::desc('closed_at')],
                pageSize: 25,
                accessOps: $ops,
            ),
            new ListingDefinition(
                id: self::LISTING_UPDATES,
                entityType: MonitorEntityTypes::OFFICIAL_UPDATE,
                // Never sorted or filtered by `answers_ask`: Internal.
                sorts: [Sort::desc('published_at')],
                pageSize: 25,
                accessOps: $ops,
            ),
        ];
    }

    /**
     * Three commands, each with a deliberately narrow surface.
     *
     * @return iterable<HandlerCommand>
     */
    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'sagamok:monitor-public',
            description: 'Observe the Sagamok public website for changes. Public pages only; never the members portal.',
            options: [
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Report what would change without writing anything at all.',
                ),
            ],
            handler: function (SymfonyCommandIO $io): int {
                $etm = $this->entityTypeManager();
                $database = $this->database();
                if ($etm === null || $database === null) {
                    $io->error('sagamok:monitor-public requires a booted kernel.');

                    return 1;
                }

                $dryRun = (bool) ($io->option('dry-run') ?? false);
                $configuration = MonitorConfiguration::fromConfig($this->config);

                // The production fetcher is constructed here and nowhere else,
                // bound to the configured origin. Tests build
                // MonitorPublicCommand directly with a fixture fetcher, so no
                // test run can reach the network.
                return new MonitorPublicCommand(
                    $etm,
                    $database,
                    new HttpPageFetcher($configuration->originUrl),
                    $configuration,
                )->run($io, $dryRun, time());
            },
        );

        yield new HandlerCommand(
            name: 'sagamok:monitor-triage',
            description: 'Maintainer report over the monitor Internal editorial values. Every read is ledgered.',
            options: [
                new HandlerOption(
                    name: 'justification',
                    mode: HandlerOptionMode::Required,
                    description: 'Why these Internal values are being read. Recorded in the strict ledger.',
                ),
            ],
            handler: function (SymfonyCommandIO $io): int {
                $etm = $this->entityTypeManager();
                if ($etm === null) {
                    $io->error('sagamok:monitor-triage requires a booted kernel.');

                    return 1;
                }

                $issuer = $this->resolve(CliFieldReadCapabilityIssuer::class);
                $reader = $this->resolve(AuditedFieldRead::class);
                if (!$issuer instanceof CliFieldReadCapabilityIssuer || !$reader instanceof AuditedFieldRead) {
                    // Fail closed: without the audited path there is no
                    // sanctioned way to read these values at all.
                    $io->error('The audited field-read services are not available; triage cannot run.');

                    return 1;
                }

                return new MonitorTriageCommand($etm, $issuer, $reader)->run(
                    $io,
                    (string) ($io->option('justification') ?? ''),
                    new \DateTimeImmutable('+5 minutes'),
                );
            },
        );

        yield new HandlerCommand(
            name: 'sagamok:monitor-redact-event',
            description: 'Redact one monitor event, retaining a stub so the log cannot silently develop holes.',
            options: [
                new HandlerOption(name: 'event', mode: HandlerOptionMode::Required, description: 'The monitor_event id.'),
                new HandlerOption(name: 'reason', mode: HandlerOptionMode::Required, description: 'One of: ' . implode(', ', MonitorRedactEventCommand::REASONS)),
            ],
            handler: function (SymfonyCommandIO $io): int {
                $etm = $this->entityTypeManager();
                $database = $this->database();
                if ($etm === null || $database === null) {
                    $io->error('sagamok:monitor-redact-event requires a booted kernel.');

                    return 1;
                }

                // Redaction reaches the snapshot table, not just the event row:
                // the retained body is the thing being redacted.
                return new MonitorRedactEventCommand($etm, new CollectorState($database))->run(
                    $io,
                    (string) ($io->option('event') ?? ''),
                    (string) ($io->option('reason') ?? ''),
                    time(),
                );
            },
        );
    }

    /**
     * Resolve the entity type manager, or null when there is no booted kernel.
     *
     * These two helpers were **missing entirely** while all three command
     * handlers above called them, so every monitor CLI command died with
     * "Call to undefined method" the moment it was invoked through the real
     * CLI. Nothing caught it: `ScheduleAndCommandsTest` constructs the command
     * objects directly with fixture collaborators, which is the right way to
     * test their logic but never executes the provider handler that production
     * actually runs. The gap only showed up when the acceptance run invoked
     * `bin/waaseyaa sagamok:monitor-public --dry-run` for real.
     *
     * Same shape as AnokiiContentServiceProvider and CmsContentServiceProvider:
     * resolve optionally and let the handler print an operator-facing error,
     * rather than throwing out of a console command.
     */
    private function entityTypeManager(): ?EntityTypeManager
    {
        try {
            $resolved = $this->resolve(EntityTypeManager::class);

            return $resolved instanceof EntityTypeManager ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function database(): ?DatabaseInterface
    {
        try {
            $resolved = $this->resolve(DatabaseInterface::class);

            return $resolved instanceof DatabaseInterface ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
