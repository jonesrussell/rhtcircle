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

        // The first run over a source establishes a BASELINE: those items were
        // not created now, they were merely observed for the first time. Events
        // from this run are flagged so the dashboard can say "First observed by
        // this monitor" rather than implying the Nation published 200 documents
        // the day we switched the collector on.
        //
        // Derived from whether this source has ANY tracked state, not from
        // `last_success` (review finding 4). `last_success` only advances when
        // health is `ok`, and health is `degraded` whenever a single URL in the
        // target set fails. So one page that permanently 500s or times out held
        // `last_success` at 0 forever, making EVERY run a baseline run — and
        // every genuinely new document the Nation published thereafter was
        // labelled "First observed by this monitor". The distinction the whole
        // dashboard is built around inverted silently, with nothing visible but
        // an "unreachable" badge operators learn to ignore.
        //
        // Bounded by construction: state rows are written for every observed,
        // absent or excluded target, so the baseline closes after the first run
        // that reached anything at all and cannot reopen.
        $isBaselineRun = $this->state->allForSource($sourceKey) === [];

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
            // Items whose retention is suppressed by a redaction. Counted
            // separately: this is our own obligation, not an observation about
            // the publisher's site, and folding it into `not_retained` would
            // misreport a redaction as a `noindex`.
            'suppressed' => 0,
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

            // --- final effective-URL validation, before anything reads the body ---
            //
            // The URL we asked for and the URL we ended up at are different
            // facts. Discovery vetted the first; only this check vets the
            // second. A public URL that redirects into `/members/...` arrives
            // here with a protected `finalUrl` and a members-area body, and
            // everything downstream — parsing, titling, hashing, snapshotting,
            // projecting — would otherwise treat it as ordinary content.
            //
            // Deliberately independent of what the body looks like: a portal
            // page served at 200 with no login form is still not ours. Neither
            // the path nor the body is retained, and the event carries no
            // locator (spec §3, "no portal URLs").
            $protectedFinalUrl = CrawlBoundary::isProtected($result->finalUrl);

            $exclusion = $protectedFinalUrl
                ? ExclusionKind::AuthRequired
                : GateDetector::classify($result->statusCode, $result->finalUrl, $result->body, $result->headers);
            if ($exclusion !== null) {
                $reason = $protectedFinalUrl
                    ? 'protected_path'
                    : (string) GateDetector::reason($result->statusCode, $result->finalUrl, $result->body, $result->headers);

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
                    // Purges the retained body, snapshots and content hash.
                    $recorded = $this->state->recordExclusion($sourceKey, $itemKey, $exclusion, $reason, $now);
                    $publicRef = $known[$itemKey]['item_public_ref'] ?? '';
                    if ($recorded && $publicRef !== '') {
                        // No locator on the event: for a protected path the URL
                        // is itself something we do not store.
                        $this->recordEvent($sourceKey, $publicRef, $exclusion->eventType(), $now, 'gate_probe', '');
                        $this->touchItem($sourceKey, $publicRef, null, null, $now, $exclusion->eventType());
                        // The title was learned from a body we are no longer
                        // entitled to hold, so it goes with the body. Leaving it
                        // would keep a readable fragment of excluded content on
                        // the dashboard indefinitely.
                        $this->neutralizeTitle($sourceKey, $publicRef, $exclusion);
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

            // Last line of defence. Everything below reads the body: hashing,
            // snapshotting, title extraction, projection. If any earlier stage
            // has a gap, this fails loudly rather than quietly retaining
            // protected material — a silent skip here would restore exactly the
            // class of bug this machinery exists to prevent.
            CrawlBoundary::assertPublic($result->finalUrl, 'hash and store');

            // Retention suppression outranks everything below.
            //
            // A redacted item must not be silently rehydrated. Without this the
            // redaction was durable only until the page next changed: the
            // change branch would re-hash the body, append a fresh snapshot and
            // restore the public locator, quietly undoing a removal someone had
            // asked for — and nobody would look again, because the command had
            // already reported success.
            //
            // Checked BEFORE the body is hashed or stored, and never lifted
            // here: only an audited maintainer action clears it.
            if ($this->state->isSuppressed($sourceKey, $itemKey)) {
                $seen[$itemKey] = true;
                ++$report['suppressed'];
                continue;
            }

            $hash = ContentNormalizer::hash($result->body);
            $snapshot = ContentNormalizer::snapshot($result->body);

            // A redaction obligation follows the normalized content, not the
            // URL it happened to occupy when the request was received. Match
            // against the salted tombstone before creating an item, extracting
            // a title, storing a snapshot or publishing an event. This closes
            // the URL-move hole where the old state hash had correctly been
            // cleared and therefore could never participate in ordinary move
            // detection.
            $contentSuppression = $this->state->activeSuppressionForContent($sourceKey, $hash);
            if ($contentSuppression !== null) {
                ++$report['suppressed'];
                if (!$dryRun) {
                    $this->state->propagateSuppression($sourceKey, $itemKey, $contentSuppression, $now);
                }
                continue;
            }

            $existing = $known[$itemKey] ?? null;

            if ($existing === null) {
                // The page may have moved while suppressed and then been
                // explicitly released. In that case the new URL has no crawl
                // state, but the cleared content-level tombstone still carries
                // the original public identity. Recover that identity rather
                // than minting a second item and an unsupported `appeared`
                // event.
                $clearedMove = $this->state->clearedSuppressionForContent($sourceKey, $hash);
                if ($clearedMove !== null) {
                    $publicRef = $clearedMove['item_public_ref'];
                    $fromItemKey = $this->state->itemKeyForPublicRef($sourceKey, $publicRef);
                    if ($fromItemKey === null) {
                        throw new \LogicException(sprintf(
                            'Cleared suppression for "%s" has no collector identity to restore.',
                            $publicRef,
                        ));
                    }

                    $report['events'][] = ['type' => 'became_retainable', 'item' => $publicRef, 'moved' => true];
                    if (!$dryRun) {
                        $this->state->moveIdentity(
                            $sourceKey,
                            $fromItemKey,
                            $itemKey,
                            $publicRef,
                            $hash,
                            strlen($snapshot),
                            $now,
                        );
                        $this->state->appendSnapshot($sourceKey, $itemKey, $hash, $snapshot, $now);
                        $this->touchItem($sourceKey, $publicRef, $url, $hash, $now, 'became_retainable');
                        $this->restoreTitle($sourceKey, $publicRef, $result->body);
                        $this->recordEvent($sourceKey, $publicRef, 'became_retainable', $now, 'direct_fetch', $url);
                        $this->state->consumeClearedSuppression($sourceKey, $clearedMove['item_key']);
                    }
                    continue;
                }

                // Deferred: a move can only be recognised once the whole crawl
                // has run, because it needs to know which old URLs are
                // CONFIRMED absent. Deciding here would merge two live pages
                // that happen to share content.
                $newThisRun[$itemKey] = ['url' => $url, 'hash' => $hash, 'snapshot' => $snapshot, 'body' => $result->body];
                continue;
            }

            // An audited clear is a recovery transition, not evidence that the
            // publisher changed the page. Redaction deliberately erased the old
            // hash, so comparing it with the incoming hash would manufacture a
            // `content_changed` event. Restore the title and baseline from the
            // current body, then consume the cleared tombstone only after the
            // recovery writes have succeeded.
            if ($this->state->clearedSuppression($sourceKey, $itemKey)) {
                $publicRef = (string) $existing['item_public_ref'];
                if ($publicRef === '') {
                    $newThisRun[$itemKey] = [
                        'url' => $url,
                        'hash' => $hash,
                        'snapshot' => $snapshot,
                        'body' => $result->body,
                    ];
                    continue;
                }

                $report['events'][] = ['type' => 'became_retainable', 'item' => $publicRef];
                if (!$dryRun) {
                    $this->state->record($sourceKey, $itemKey, $publicRef, $hash, strlen($snapshot), $now);
                    $this->state->appendSnapshot($sourceKey, $itemKey, $hash, $snapshot, $now);
                    $this->touchItem($sourceKey, $publicRef, $url, $hash, $now, 'became_retainable');
                    $this->restoreTitle($sourceKey, $publicRef, $result->body);
                    $this->recordEvent($sourceKey, $publicRef, 'became_retainable', $now, 'direct_fetch', $url);
                    $this->state->consumeClearedSuppression($sourceKey, $itemKey);
                }
                continue;
            }

            // Recovery is decided BEFORE change detection, and the order is
            // load-bearing. Exclusion purges the retained body and content hash
            // (review finding 2), so on the way back out there is no stored
            // fingerprint to compare against — by design, not by accident.
            //
            // Left in the other order, the empty hash never equals the incoming
            // one and every recovery would publish "Page content changed": an
            // assertion about the Nation's website that we cannot support,
            // since we destroyed the only evidence that could have supported
            // it. The page may well be byte-identical. What we actually know is
            // that it is publicly retainable again, so that is what we say, and
            // we re-establish the baseline silently.
            if ($existing['exclusion_kind'] !== '') {
                // A page first observed WHILE excluded has a state row with an
                // empty ref, because we deliberately never acknowledged it
                // publicly. It has no public history to recover to, so
                // "became_retainable" would be a claim about a past that never
                // existed — and the event would be an orphan, since there is no
                // item to attach it to.
                //
                // Its first public sighting is a first sighting. Fall through to
                // the same baseline path a never-before-seen URL takes.
                if ((string) $existing['item_public_ref'] === '') {
                    $newThisRun[$itemKey] = [
                        'url' => $url,
                        'hash' => $hash,
                        'snapshot' => $snapshot,
                        'body' => $result->body,
                    ];
                    continue;
                }

                $report['events'][] = ['type' => 'became_retainable', 'item' => $existing['item_public_ref']];
                if (!$dryRun) {
                    $this->state->record($sourceKey, $itemKey, $existing['item_public_ref'], $hash, strlen($snapshot), $now);
                    $this->state->appendSnapshot($sourceKey, $itemKey, $hash, $snapshot, $now);
                    $this->touchItem($sourceKey, $existing['item_public_ref'], $url, $hash, $now, 'became_retainable');
                    // The neutral exclusion label described a page we were not
                    // retaining. We are retaining it again and hold the current
                    // body, so leaving "Page not retained at publisher request"
                    // on the dashboard beside a live locator and a fresh
                    // snapshot would be a false statement we have the evidence
                    // to correct.
                    $this->restoreTitle($sourceKey, $existing['item_public_ref'], $result->body);
                    $this->recordEvent($sourceKey, $existing['item_public_ref'], 'became_retainable', $now, 'direct_fetch', $url);
                }
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

            // Redaction is our own retention obligation, not a statement that
            // the publisher removed a page. A suppressed item must therefore
            // never accumulate absence runs or emit a later disappearance.
            if ($this->state->isSuppressed($sourceKey, $itemKey)) {
                continue;
            }

            // Only a server that SAID the page is gone advances the counter.
            // A key we never reached this run (indeterminate error, or simply
            // not in the crawl) tells us nothing about whether it still exists.
            if (!in_array($itemKey, $confirmedAbsentKeys, true)) {
                continue;
            }

            // A URL first seen already excluded has no public ref, by design —
            // we never publicly acknowledged it (review finding 3). Without
            // this guard its later removal emitted a `disappeared` event with
            // an empty `item_public_ref`, which the timeline listing happily
            // rendered as "No longer reachable" for an item that had never
            // appeared on the dashboard. That publishes the lifecycle of a URL
            // the monitor deliberately declined to acknowledge — and the
            // paired `touchItem()` was a guaranteed no-op, so the two writes
            // disagreed about whether anything had happened.
            //
            // Symmetric with the exclusion path, which already guards on
            // `$publicRef !== ''` before recording an event.
            if ((string) $row['item_public_ref'] === '') {
                continue;
            }

            $absentRuns = $dryRun ? $row['absent_runs'] + 1 : $this->state->incrementAbsent($sourceKey, $itemKey, $now);

            // Exactly at the transition, not on every later run: a removal is
            // announced once. `>= 2` would re-announce the same disappearance
            // every hour for as long as the page stayed gone.
            if ($absentRuns === 2) {
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

    /**
     * Replace a content-derived title with neutral status wording.
     *
     * The title was extracted from the page body. Once that body is excluded —
     * gated, protected or `noindex` — the title is a surviving fragment of
     * content we have undertaken not to retain, and it is rendered on the
     * dashboard and in listings. Purging the snapshot while leaving
     * "Member bulletin — March 2026" on screen would honour the letter of the
     * retention rule and none of its point.
     *
     * The replacement says what the monitor knows without quoting the page:
     * that an item exists at this reference and why it is no longer collected.
     * The public URL is cleared for `AuthRequired` because a protected locator
     * is itself not ours to publish; a `noindex` page is usually still public,
     * so its URL is left in place.
     */
    /**
     * Re-derive the title from the page we are once again entitled to hold.
     *
     * The inverse of {@see neutralizeTitle()}. Exclusion replaces the title
     * with a neutral label because the title was a fragment of content we had
     * undertaken not to retain; recovery must undo that, or the dashboard
     * keeps asserting "Page not retained at publisher request" next to a live
     * locator and a freshly stored snapshot.
     *
     * Reads the CURRENT body, not any remembered value — the title may have
     * changed while the page was gated, and the old one was purged anyway.
     */
    private function restoreTitle(string $sourceKey, string $publicRef, string $body): void
    {
        $item = $this->findItem($sourceKey, $publicRef);
        if ($item === null) {
            return;
        }

        $item->set('title', $this->extractTitle($body) ?: $publicRef);
        $this->items->save($item, validate: false);
    }

    private function neutralizeTitle(string $sourceKey, string $publicRef, ExclusionKind $kind): void
    {
        $item = $this->findItem($sourceKey, $publicRef);
        if ($item === null) {
            return;
        }

        $item->set('title', $kind === ExclusionKind::AuthRequired
            ? 'Page requiring sign-in (title not retained)'
            : 'Page not retained at publisher request');

        if ($kind === ExclusionKind::AuthRequired) {
            $item->set('public_url', '');
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
        // The invariant, enforced HERE rather than in each calling branch.
        //
        // A monitor event is a public statement about a page. With an empty
        // `item_public_ref` it is a statement about nothing: the timeline
        // listing filters only on `redacted_at`, and the templates switch on
        // `event_type` alone, so an orphan renders to readers as a real finding
        // attached to no item. It also cannot be redacted, because redaction
        // addresses an item by its public ref.
        //
        // This was fixed twice in individual branches — the absence pass, then
        // the exclusion path — and re-appeared a third time on the recovery
        // branch, because a guard in N places only holds until someone adds
        // branch N+1. One boundary check cannot be forgotten.
        if ($publicRef === '') {
            throw new \LogicException(sprintf(
                'Refused to persist a "%s" event with an empty public reference. An item must be '
                . 'publicly acknowledged before any event about it can be published.',
                $type,
            ));
        }

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
