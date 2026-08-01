<?php

declare(strict_types=1);

namespace App\Monitor;

use App\Provider\SagamokMonitorServiceProvider;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Listing\ExposedFilterValues;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ListingResolver;

/**
 * The **only** way monitor data reaches a template or any other reader (spec
 * §6.3).
 *
 * `view()` emits a **closed list** of keys. It is an allow-list, not a
 * deny-list, and that asymmetry is the whole design: a deny-list silently
 * publishes every field added later, whereas a field added to an entity
 * tomorrow appears here only if someone puts it here — at which point they must
 * redo the §6.0 composition analysis.
 *
 * Never emitted, under any circumstance: `severity`, `answers_ask`,
 * `last_error`, `notes`, `method_note`, `review_note`, `item_key`,
 * `content_hash`, `normalized_bytes`, `snapshot`, any entity row id, and
 * anything at all from the §3.6 side table.
 *
 * Note the exclusions are not merely "sensitive fields". `severity` and
 * `answers_ask` are editorial judgements, and a public list *ordered* by one is
 * a disclosure even when the field itself is never rendered — which is why the
 * listings sort by date and this projection cannot supply the value.
 */
final class SagamokMonitorRepository
{
    /**
     * The closed projection, per entity type. Adding a key here is a decision
     * that requires re-running the composition threat model.
     *
     * @var array<string, list<string>>
     */
    private const PROJECTION = [
        MonitorEntityTypes::SOURCE => [
            'key', 'label', 'origin_url', 'health', 'last_check_completed', 'last_success',
        ],
        MonitorEntityTypes::ITEM => [
            'public_ref', 'title', 'public_url', 'doc_kind', 'change_status',
            'first_seen', 'last_seen', 'changed_at', 'disappeared_at', 'event_count',
        ],
        MonitorEntityTypes::EVENT => [
            'item_public_ref', 'event_type', 'observed_at', 'effective_at',
            'evidence_kind', 'evidence_url', 'evidence_captured_at',
        ],
        MonitorEntityTypes::ISSUE => [
            'slug', 'title', 'issue_state', 'opened_at', 'status_changed_at', 'closed_at',
            'summary', 'what_is_asked', 'related_article_slugs', 'related_item_public_refs',
        ],
        MonitorEntityTypes::OFFICIAL_UPDATE => [
            'issue_slug', 'published_at', 'source_label', 'source_url', 'summary',
        ],
        MonitorEntityTypes::PORTAL_ACCESS_STATE => [
            'state', 'verified_on', 'statement',
        ],
        MonitorEntityTypes::PORTAL_UPDATE_NOTE => [
            'title_supplied', 'supplied_on', 'official_date', 'summary',
        ],
    ];

    /**
     * A source is "stalled" when its last completed check is older than this.
     * Rendering it is required, not optional: a monitoring dashboard that
     * silently stops checking is worse than no dashboard, because it invites
     * members to read a stale page as current.
     */
    private const STALLED_AFTER_SECONDS = 3 * 3600;

    public function __construct(
        private readonly EntityTypeManager $entityTypes,
        private readonly ?ListingDefinitionRegistry $listings = null,
        private readonly ?ListingResolver $resolver = null,
    ) {}

    /**
     * Resolve a registered listing and project every row.
     *
     * **This is the only way the dashboard reads monitor data.** Going through
     * `ListingResolver` rather than scanning repositories is what makes the
     * seven `ListingDefinition`s real: they carry the access ops, the page
     * size, the sort and the cache tags, and resolving them is what applies
     * the bespoke `monitor.dashboard_read` gate. A controller that queried
     * entities directly would bypass all of it and leave the definitions as
     * decoration.
     *
     * @return array{rows: list<array<string, scalar>>, pagination: array<string, mixed>}
     */
    public function resolveListing(string $listingId, int $page = 1, int $now = 0): array
    {
        if ($this->listings === null || $this->resolver === null) {
            throw new \LogicException(sprintf(
                'The monitor dashboard requires the Listing pipeline; "%s" cannot be resolved without it.',
                $listingId,
            ));
        }

        $definition = $this->listings->get($listingId);

        // Pagination is read by the resolver from the request's `?page=`
        // parameter, so it is not threaded through here. `$page` is retained on
        // the signature because callers reason in pages and the returned
        // pagination block is what templates render.
        $result = $this->resolver->resolve($definition);

        $rows = [];
        foreach ($result->rows as $row) {
            if ($row instanceof EntityInterface) {
                $rows[] = $this->view($row, $now);
            }
        }

        return [
            'rows' => $rows,
            'pagination' => [
                'page' => $result->pagination->page,
                'page_size' => $result->pagination->pageSize,
                'total_rows' => $result->pagination->totalRows,
                'total_pages' => $result->pagination->totalPages,
                'has_prev' => $result->pagination->hasPrev,
                'has_next' => $result->pagination->hasNext,
            ],
        ];
    }

    /**
     * Project one entity to its closed public shape.
     *
     * @return array<string, scalar>
     */
    public function view(EntityInterface $entity, int $now = 0): array
    {
        $typeId = $entity->getEntityTypeId();
        $allowed = self::PROJECTION[$typeId] ?? null;
        if ($allowed === null) {
            // An unknown type projects to nothing rather than to everything.
            // Fail closed: a new monitor entity type must be added to
            // PROJECTION deliberately, not inherit a default of "publish it".
            return [];
        }

        $out = [];
        foreach ($allowed as $field) {
            $value = $entity->get($field);
            $out[$field] = is_scalar($value) ? $value : (string) ($value ?? '');
        }

        if ($typeId === MonitorEntityTypes::SOURCE) {
            $lastCheck = (int) ($out['last_check_completed'] ?? 0);
            $out['stalled'] = $now > 0 && ($lastCheck === 0 || ($now - $lastCheck) > self::STALLED_AFTER_SECONDS);
        }

        if ($typeId === MonitorEntityTypes::PORTAL_ACCESS_STATE) {
            // Month precision only (spec §7.1): a day-precision verification
            // date invites inference about who checked and when.
            $out['last_verified_on'] = (string) ($out['verified_on'] ?? '');
            unset($out['verified_on']);
        }

        return $out;
    }

    /**
     * Project a list of entities.
     *
     * @param iterable<EntityInterface> $entities
     * @return list<array<string, scalar>>
     */
    public function viewAll(iterable $entities, int $now = 0): array
    {
        $out = [];
        foreach ($entities as $entity) {
            $out[] = $this->view($entity, $now);
        }

        return $out;
    }

    /**
     * The keys this projection will emit for a type — used by the §9.1
     * composition test to assert the projection is a **closed set**, rather
     * than checking a handful of fields it happens to remember.
     *
     * @return list<string>
     */
    public static function projectedKeys(string $entityTypeId): array
    {
        return self::PROJECTION[$entityTypeId] ?? [];
    }

    /** @return array<string, list<string>> */
    public static function projection(): array
    {
        return self::PROJECTION;
    }
}
