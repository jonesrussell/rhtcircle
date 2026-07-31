<?php

declare(strict_types=1);

namespace App\Provider;

use App\Monitor\MonitorEntityTypes;
use App\Monitor\MonitorRepository;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\HasListingsInterface;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\Sort;
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
final class SagamokMonitorServiceProvider extends ServiceProvider implements HasListingsInterface
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
}
