<?php

declare(strict_types=1);

namespace App\Monitor;

use App\Entity\MonitorEvent;
use App\Entity\MonitorItem;
use App\Entity\MonitorSource;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * One collection run over a **public website** source (spec §4).
 *
 * Scope, fixed by construction rather than by policy: the collector reaches the
 * network only through {@see PageFetcherInterface}, which has no credential,
 * cookie, archive or search-index surface. There is no members-portal path here
 * and none can be added without changing that interface.
 *
 * Two properties matter more than the rest and both have dedicated tests:
 *
 *  - **Idempotence.** Two runs over unchanged upstream state produce zero new
 *    events. Anything else fills the timeline with noise and trains readers to
 *    ignore it.
 *  - **A fetch failure is never a content change.** Transport failures move
 *    source health and nothing else; a removal needs two consecutive absent
 *    runs before it is published.
 */
final class PublicSiteCollector
{
    /** Approved crawl limits (spec §0). */
    public const int MAX_URLS_PER_RUN = 300;

    public function __construct(
        private readonly EntityRepositoryInterface $sources,
        private readonly EntityRepositoryInterface $items,
        private readonly EntityRepositoryInterface $events,
        private readonly CollectorState $state,
        private readonly PageFetcherInterface $fetcher,
    ) {}

    /**
     * Run the collector for one source.
     *
     * @param list<string> $urls Same-origin public URLs to observe this run.
     * @param bool $dryRun When true **nothing is written**: no entity, no side-table
     *   row, no event, no snapshot, no source-health update. The returned report
     *   is identical in shape to a live run, so `--dry-run` is a genuine preview
     *   rather than a different code path that happens to look similar.
     * @return array<string, mixed> Report of what happened (or would happen).
     */
    public function run(MonitorSource $source, array $urls, int $now, bool $dryRun = false): array
    {
        if (!$dryRun) {
            $this->state->ensureTable();
        }

        $sourceKey = (string) $source->get('key');
        $originUrl = (string) $source->get('origin_url');

        // The first successful run over a source establishes a BASELINE: those
        // items were not created now, they were merely observed for the first
        // time. Events from this run are flagged so the dashboard can say
        // "First observed by this monitor" rather than implying the Nation
        // published 200 documents the day we switched the collector on.
        $isBaselineRun = ((int) $source->get('last_success')) === 0;

        $report = [
            'source_key' => $sourceKey,
            'dry_run' => $dryRun,
            'baseline_run' => $isBaselineRun,
            'observed' => 0,
            'skipped_off_origin' => 0,
            'truncated_at_limit' => false,
            'events' => [],
            'fetch_failures' => 0,
            'gated' => 0,
            'not_retained' => 0,
            'indeterminate' => [],
            'empty_target_set' => false,
            'exit_code' => 0,
            'health' => 'ok',
        ];

        // Same-origin only, and never more than the approved per-run ceiling.
        $eligible = [];
        foreach ($urls as $url) {
            if (!UrlNormalizer::isSameOrigin($url, $originUrl)) {
                ++$report['skipped_off_origin'];
                continue;
            }
            $eligible[UrlNormalizer::itemKey($url)] = $url;
        }
        if (count($eligible) > self::MAX_URLS_PER_RUN) {
            $eligible = array_slice($eligible, 0, self::MAX_URLS_PER_RUN, true);
            $report['truncated_at_limit'] = true;
        }

        // A run with nothing to look at is a configuration failure, not a
        // healthy run. Reporting health=ok here would tell members the site is
        // being watched when nothing is being fetched at all.
        if ($eligible === []) {
            $report['health'] = 'failing';
            $report['empty_target_set'] = true;
            $report['exit_code'] = 1;

            return $report;
        }

        $known = $this->state->allForSource($sourceKey);
        $seen = [];

        // Absence must be CONFIRMED within this run before it can feed the
        // move detector: identical content at a second still-live URL is two
        // pages, not one that moved.
        $confirmedAbsentKeys = [];

        /** @var array<string, array{url: string, hash: string, snapshot: string, body: string}> */
        $newThisRun = [];

        foreach ($eligible as $itemKey => $url) {
            $result = $this->fetcher->fetch($url);

            // --- transport failure: health only, never a content change ---
            if (!$result->ok) {
                ++$report['fetch_failures'];
                // Deliberately NOT marked absent: an unreachable site is not a
                // site that removed a page, and treating it as one would
                // publish a false "document disappeared".
                $seen[$itemKey] = true;
                continue;
            }

            // --- collection gate, before hashing or storing anything ---
            $exclusion = GateDetector::classify($result->statusCode, $result->finalUrl, $result->body, $result->headers);
            if ($exclusion !== null) {
                $reason = (string) GateDetector::reason($result->statusCode, $result->finalUrl, $result->body, $result->headers);

                // Counted separately. `noindex` says nothing about access, so
                // folding it into the gated count would inflate a figure the
                // dashboard presents as "pages that now require sign-in".
                if ($exclusion === ExclusionKind::AuthRequired) {
                    ++$report['gated'];
                } else {
                    ++$report['not_retained'];
                }

                $seen[$itemKey] = true;

                // Emit ONCE per transition. A page that stays gated would
                // otherwise produce an event every hour, burying real findings
                // under a repeating one.
                $alreadyExcluded = ($known[$itemKey]['exclusion_kind'] ?? '') === $exclusion->value;
                $isTransition = !$alreadyExcluded;

                if ($isTransition) {
                    $report['events'][] = [
                        'type' => $exclusion->eventType(),
                        'item' => $known[$itemKey]['item_public_ref'] ?? null,
                        'reason' => $reason,
                        'kind' => $exclusion->value,
                    ];
                }

                if (!$dryRun) {
                    $recorded = $this->state->recordExclusion($sourceKey, $itemKey, $exclusion, $reason, $now);
                    $publicRef = $known[$itemKey]['item_public_ref'] ?? '';
                    if ($recorded && $publicRef !== '') {
                        $this->recordEvent($sourceKey, $publicRef, $exclusion->eventType(), $now, 'gate_probe', '');
                        $this->touchItem($sourceKey, $publicRef, null, null, $now, $exclusion->eventType());
                    }
                }
                // No hash, no snapshot, no body retained — for either kind.
                continue;
            }

            if (self::isConfirmedAbsence($result->statusCode)) {
                // 404/410 are the server stating the resource is not there.
                // Handled by the absence pass below.
                $confirmedAbsentKeys[] = $itemKey;
                continue;
            }

            if ($result->statusCode >= 400) {
                // 408, 429, 5xx and anything else indeterminate: the server did
                // not say the page is gone, it said it could not answer. Two
                // 500s in a row must never publish "disappeared". Health only.
                ++$report['fetch_failures'];
                $report['indeterminate'][] = $result->statusCode;
                $seen[$itemKey] = true;
                continue;
            }

            ++$report['observed'];
            $seen[$itemKey] = true;

            $hash = ContentNormalizer::hash($result->body);
            $snapshot = ContentNormalizer::snapshot($result->body);
            $existing = $known[$itemKey] ?? null;

            if ($existing === null) {
                // Deferred: a move can only be recognised once the whole crawl
                // has run, because it needs to know which old URLs are
                // CONFIRMED absent. Deciding here would merge two live pages
                // that happen to share content.
                $newThisRun[$itemKey] = ['url' => $url, 'hash' => $hash, 'snapshot' => $snapshot, 'body' => $result->body];
                continue;
            }

            if ($existing['content_hash'] !== $hash) {
                $report['events'][] = ['type' => 'content_changed', 'item' => $existing['item_public_ref']];
                if (!$dryRun) {
                    $this->state->record($sourceKey, $itemKey, $existing['item_public_ref'], $hash, strlen($snapshot), $now);
                    $this->state->appendSnapshot($sourceKey, $itemKey, $hash, $snapshot, $now);
                    $this->touchItem($sourceKey, $existing['item_public_ref'], $url, $hash, $now, 'changed');
                    $this->recordEvent($sourceKey, $existing['item_public_ref'], 'content_changed', $now, 'direct_fetch', $url);
                }
                continue;
            }

            // Seen again after one or more absent runs → a return, even though
            // the bytes are unchanged. Without this the item keeps its
            // `disappeared` status and stale `disappeared_at` forever while
            // plainly being served again, which is a false statement on the
            // dashboard.
            if ($existing['absent_runs'] > 0) {
                $report['events'][] = ['type' => 'reappeared', 'item' => $existing['item_public_ref']];
                if (!$dryRun) {
                    $this->state->clearAbsent($sourceKey, $itemKey, $now);
                    $this->touchItem($sourceKey, $existing['item_public_ref'], $url, $hash, $now, 'reappeared');
                    $this->recordEvent($sourceKey, $existing['item_public_ref'], 'reappeared', $now, 'direct_fetch', $url);
                }
                continue;
            }

            // Recovery: the page was excluded (gated or noindex) and is now
            // publicly retainable again. Announced once, symmetrically with the
            // transition into exclusion.
            if ($existing['exclusion_kind'] !== '') {
                $report['events'][] = ['type' => 'became_retainable', 'item' => $existing['item_public_ref']];
                if (!$dryRun) {
                    $this->state->record($sourceKey, $itemKey, $existing['item_public_ref'], $hash, strlen($snapshot), $now);
                    $this->state->appendSnapshot($sourceKey, $itemKey, $hash, $snapshot, $now);
                    $this->touchItem($sourceKey, $existing['item_public_ref'], $url, $hash, $now, 'became_retainable');
                    $this->recordEvent($sourceKey, $existing['item_public_ref'], 'became_retainable', $now, 'direct_fetch', $url);
                }
                continue;
            }

            // Hash equal → no event. Refresh last_seen only (spec §4.3 step 6).
            // This branch is what makes the run idempotent.
            if (!$dryRun) {
                $this->state->clearAbsent($sourceKey, $itemKey, $now);
                $this->touchItem($sourceKey, $existing['item_public_ref'], $url, $hash, $now, null);
            }
        }

        // --- new items: a move, or genuinely new ---------------------------
        // Resolved after the crawl so `$confirmedAbsentKeys` is complete. A URL
        // rename shows up as one key absent and an identical body at another;
        // two separate live pages never satisfy that, however alike they are.
        foreach ($newThisRun as $itemKey => $found) {
            $moved = $this->state->findMoveCandidate($sourceKey, $found['hash'], $itemKey, $confirmedAbsentKeys);

            if ($moved !== null) {
                $report['events'][] = ['type' => 'reappeared', 'item' => $moved['item_public_ref'], 'moved' => true];
                if (!$dryRun) {
                    // Atomic: the old key is removed as the new one takes the
                    // ref, so the stale key cannot later report a false
                    // disappearance for a page being served at its new URL.
                    $this->state->moveIdentity(
                        $sourceKey,
                        $moved['item_key'],
                        $itemKey,
                        $moved['item_public_ref'],
                        $found['hash'],
                        strlen($found['snapshot']),
                        $now,
                    );
                    $this->state->appendSnapshot($sourceKey, $itemKey, $found['hash'], $found['snapshot'], $now);
                    $this->touchItem($sourceKey, $moved['item_public_ref'], $found['url'], $found['hash'], $now, 'reappeared');
                    $this->recordEvent($sourceKey, $moved['item_public_ref'], 'reappeared', $now, 'direct_fetch', $found['url']);
                }
                // The old key is gone: it must not also be reported absent.
                $confirmedAbsentKeys = array_values(array_diff($confirmedAbsentKeys, [$moved['item_key']]));
                unset($known[$moved['item_key']]);
                continue;
            }

            $publicRef = $dryRun ? $sourceKey . '-preview' : $this->state->nextPublicRef($sourceKey);
            $report['events'][] = ['type' => 'appeared', 'item' => $publicRef, 'baseline' => $isBaselineRun];
            if (!$dryRun) {
                $this->state->record($sourceKey, $itemKey, $publicRef, $found['hash'], strlen($found['snapshot']), $now);
                $this->state->appendSnapshot($sourceKey, $itemKey, $found['hash'], $found['snapshot'], $now);
                $this->createItem($sourceKey, $publicRef, $found['url'], $found['body'], $now);
                $this->recordEvent($sourceKey, $publicRef, 'appeared', $now, 'direct_fetch', $found['url'], $isBaselineRun);
            }
        }

        // --- absence pass: two consecutive runs before a removal is published ---
        foreach ($known as $itemKey => $row) {
            if (isset($seen[$itemKey])) {
                continue;
            }

            // Only a server that SAID the page is gone advances the counter.
            // A key we never reached this run (indeterminate error, or simply
            // not in the crawl) tells us nothing about whether it still exists.
            if (!in_array($itemKey, $confirmedAbsentKeys, true)) {
                continue;
            }

            $absentRuns = $dryRun ? $row['absent_runs'] + 1 : $this->state->incrementAbsent($sourceKey, $itemKey, $now);

            // Exactly at the transition, not on every later run: a removal is
            // announced once. `>= 2` would re-announce the same disappearance
            // every hour for as long as the page stayed gone.
            if ($absentRuns === 2) {
                $disappearedThisRun[] = $row['item_public_ref'];
                $report['events'][] = ['type' => 'disappeared', 'item' => $row['item_public_ref'], 'absent_runs' => $absentRuns];
                if (!$dryRun) {
                    $this->touchItem($sourceKey, $row['item_public_ref'], null, null, $now, 'disappeared');
                    $this->recordEvent($sourceKey, $row['item_public_ref'], 'disappeared', $now, 'absence', '');
                }
                continue;
            }

            // First absence: recorded in the side table, published nowhere.
            $report['events'][] = ['type' => 'absent_pending', 'item' => $row['item_public_ref'], 'absent_runs' => $absentRuns];
        }

        $report['health'] = $this->healthFor($report);

        // Non-zero when nothing could be read at all, so a scheduled run
        // surfaces in whatever watches exit codes rather than failing silently
        // every hour. An all-excluded or all-absent run is NOT this case: the
        // site answered, and the events carry the finding.
        if ($report['observed'] === 0 && $report['fetch_failures'] > 0) {
            $report['exit_code'] = 1;
        }

        if (!$dryRun) {
            $this->updateSourceHealth($source, $report, $now);
            $this->state->pruneExpiredSnapshots($now);
        }

        return $report;
    }

    /**
     * Source health from this run's outcome. Any fetch failure degrades health:
     * a dashboard that silently stops checking is worse than no dashboard,
     * because it invites members to read a stale page as current.
     *
     * `noindex` exclusions deliberately do NOT degrade health
     * ({@see ExclusionKind::affectsSourceHealth()}): the site is reachable and
     * behaving normally, and we are choosing not to retain the page. Flagging
     * the source unhealthy for honouring a publisher's directive would be a
     * false alarm about our own monitoring.
     *
     * @param array<string, mixed> $report
     */
    /**
     * Statuses where the server has actually stated the resource is gone.
     *
     * Everything else — 408, 429, 5xx, and anything unrecognised — means the
     * server could not answer, which is a health signal and never a removal.
     */
    private static function isConfirmedAbsence(int $statusCode): bool
    {
        return $statusCode === 404 || $statusCode === 410;
    }

    private function healthFor(array $report): string
    {
        // Health answers one question: is the MONITORING working? It is not a
        // verdict on what the monitoring found.
        //
        // A run where every page turned out to be absent, gated or noindex read
        // nothing — but the site answered every request, so the monitor is
        // healthy and the findings are carried by the events. Reporting
        // `failing` there would put an alarm on the dashboard about our own
        // infrastructure because someone else published a `noindex` tag.
        //
        // The genuinely empty case — no targets at all — is handled before any
        // fetch happens, and exits non-zero.
        if ($report['fetch_failures'] > 0 && $report['observed'] === 0) {
            return 'failing';
        }
        if ($report['fetch_failures'] > 0) {
            return 'degraded';
        }

        return 'ok';
    }

    /**
     * @param array<string, mixed> $report
     */
    private function updateSourceHealth(MonitorSource $source, array $report, int $now): void
    {
        $source->set('last_check_started', $now);
        $source->set('last_check_completed', $now);
        $source->set('health', $report['health']);

        if ($report['health'] === 'ok' && $report['exit_code'] === 0) {
            $source->set('last_success', $now);
            $source->set('consecutive_failures', 0);
            $source->set('last_error', '');
        } else {
            $source->set('consecutive_failures', ((int) $source->get('consecutive_failures')) + 1);
        }

        $this->sources->save($source, validate: false);
    }

    private function createItem(string $sourceKey, string $publicRef, string $url, string $body, int $now): void
    {
        $item = $this->items->create([
            'source_key' => $sourceKey,
            'public_ref' => $publicRef,
            'title' => $this->extractTitle($body) ?: $publicRef,
            'public_url' => $url,
            'doc_kind' => str_contains(strtolower($url), '.pdf') ? 'document' : 'page',
            'change_status' => 'new',
            'first_seen' => $now,
            'last_seen' => $now,
            'changed_at' => $now,
            'event_count' => 1,
        ]);
        $this->items->save($item, validate: false);
    }

    private function touchItem(
        string $sourceKey,
        string $publicRef,
        ?string $url,
        ?string $hash,
        int $now,
        ?string $changeStatus,
    ): void {
        $item = $this->findItem($sourceKey, $publicRef);
        if ($item === null) {
            return;
        }

        $item->set('last_seen', $now);
        if ($url !== null) {
            $item->set('public_url', $url);
        }
        if ($changeStatus !== null) {
            $item->set('change_status', $changeStatus);
            $item->set('changed_at', $now);
            $item->set('event_count', ((int) $item->get('event_count')) + 1);
        }
        if ($changeStatus === 'disappeared') {
            $item->set('disappeared_at', $now);
        }
        if ($changeStatus === 'reappeared') {
            $item->set('disappeared_at', 0);
        }

        $this->items->save($item, validate: false);
    }

    private function findItem(string $sourceKey, string $publicRef): ?MonitorItem
    {
        foreach ($this->items->findBy(['source_key' => $sourceKey, 'public_ref' => $publicRef]) as $item) {
            if ($item instanceof MonitorItem) {
                return $item;
            }
        }

        return null;
    }

    private function recordEvent(
        string $sourceKey,
        string $publicRef,
        string $type,
        int $now,
        string $evidenceKind,
        string $evidenceUrl,
        bool $isBaseline = false,
    ): void {
        $event = $this->events->create([
            'source_key' => $sourceKey,
            'item_public_ref' => $publicRef,
            'event_type' => $type,
            'observed_at' => $now,
            'effective_at' => 0,
            'evidence_kind' => $evidenceKind,
            'evidence_url' => $evidenceUrl,
            'evidence_captured_at' => $now,
            'is_baseline' => $isBaseline,
            'redacted_at' => 0,
        ]);
        $this->events->save($event, validate: false);
    }

    private function extractTitle(string $body): string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m) === 1) {
            return trim(html_entity_decode(strip_tags($m[1])));
        }

        return '';
    }

    /** @return class-string<MonitorEvent> */
    public static function eventClass(): string
    {
        return MonitorEvent::class;
    }
}
