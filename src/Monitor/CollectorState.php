<?php

declare(strict_types=1);

namespace App\Monitor;

use Waaseyaa\Database\DatabaseInterface;

/**
 * The collector's side tables (spec §3.6) — identity keys, hashes, exclusion
 * state, the absence counter, and a bounded snapshot history.
 *
 * **Not entities, deliberately.** They have no independent lifecycle, are never
 * rendered and are never related to, which is exactly the case where the app's
 * storage rule permits `DatabaseInterface` directly. Two consequences follow,
 * and both are the point:
 *
 *  - Values that never enter an entity cannot leak through an entity
 *    projection, a Listing facet, or an auto-generated route. The guarantee is
 *    structural rather than enforced by a policy someone must remember.
 *  - `absent_runs` is readable by the collector. As an `Internal` entity field
 *    it would have needed an audited capability, so the collector could not
 *    have read its own counter — and the tempting fix for that is
 *    `EntityReadRuntime::installGuard(null)`, which re-opens every field in the
 *    app. This table exists partly to keep that door shut.
 */
final class CollectorState
{
    public const string TABLE = 'monitor_collector_state';

    /** Bounded snapshot history (spec §0). */
    public const string SNAPSHOT_TABLE = 'monitor_collector_snapshot';
    public const string SUPPRESSION_TABLE = 'monitor_retention_suppression';
    public const int SNAPSHOT_RETENTION_DAYS = 90;
    public const int MAX_SNAPSHOTS_PER_ITEM = 3;

    public function __construct(private readonly DatabaseInterface $database) {}

    /**
     * Whether the side tables have been created yet.
     *
     * Every read path checks this, because a **dry run must create nothing** —
     * including these tables. Reading before any live run has happened is a
     * legitimate empty result, not an error, and making that explicit here
     * keeps `--dry-run` honestly write-free rather than write-free-except-DDL.
     */
    private function tableExists(): bool
    {
        return $this->database->schema()->tableExists(self::TABLE);
    }

    public function ensureTable(): void
    {
        $schema = $this->database->schema();

        if (!$schema->tableExists(self::TABLE)) {
            $this->database->query(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'source_key TEXT NOT NULL, '
                . 'item_key TEXT NOT NULL, '
                . 'item_public_ref TEXT NOT NULL, '
                . 'content_hash TEXT NOT NULL, '
                . 'normalized_bytes INTEGER NOT NULL DEFAULT 0, '
                . 'absent_runs INTEGER NOT NULL DEFAULT 0, '
                // The page's current exclusion state, so a gated or noindex page
                // that stays that way produces ONE event rather than one per
                // run. Stores the state only: never the body, hash or snapshot
                // of excluded content.
                . 'exclusion_kind TEXT NOT NULL DEFAULT \'\', '
                . 'exclusion_reason TEXT NOT NULL DEFAULT \'\', '
                . 'updated_at INTEGER NOT NULL, '
                . 'PRIMARY KEY (source_key, item_key)'
                . ')',
            );
            $this->database->query(
                'CREATE INDEX IF NOT EXISTS monitor_collector_state_source_idx ON ' . self::TABLE . '(source_key)',
            );
        }

        if (!$schema->tableExists(self::SNAPSHOT_TABLE)) {
            $this->database->query(
                'CREATE TABLE IF NOT EXISTS ' . self::SNAPSHOT_TABLE . ' ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'source_key TEXT NOT NULL, '
                . 'item_key TEXT NOT NULL, '
                . 'content_hash TEXT NOT NULL, '
                . 'snapshot BLOB, '
                . 'snapshot_bytes INTEGER NOT NULL DEFAULT 0, '
                . 'taken_at INTEGER NOT NULL'
                . ')',
            );
            $this->database->query(
                'CREATE INDEX IF NOT EXISTS monitor_collector_snapshot_item_idx ON '
                . self::SNAPSHOT_TABLE . '(source_key, item_key, taken_at)',
            );
            $this->database->query(
                'CREATE INDEX IF NOT EXISTS monitor_collector_snapshot_taken_idx ON '
                . self::SNAPSHOT_TABLE . '(taken_at)',
            );
        }

        $this->ensureSuppressionTable();
    }

    /**
     * Prepare the durable redaction tombstone outside any content transaction.
     *
     * Suppression deliberately has its own table. Adding columns lazily to the
     * collector-state table made every read of a pre-existing database fail
     * before the lazy migration ran, and running ALTER TABLE inside the
     * redaction transaction is an implicit commit on MySQL. A separate table
     * makes the upgrade additive and lets the command prepare storage before it
     * starts the all-or-nothing purge.
     */
    public function ensureSuppressionTable(): void
    {
        $schema = $this->database->schema();
        if ($schema->tableExists(self::SUPPRESSION_TABLE)) {
            return;
        }

        $this->database->query(
            'CREATE TABLE IF NOT EXISTS ' . self::SUPPRESSION_TABLE . ' ('
            . 'source_key TEXT NOT NULL, '
            . 'item_key TEXT NOT NULL, '
            . 'item_public_ref TEXT NOT NULL, '
            . 'fingerprint_salt TEXT NOT NULL, '
            . 'content_fingerprint TEXT NOT NULL, '
            . 'suppression_reason TEXT NOT NULL, '
            . 'suppressed_at INTEGER NOT NULL, '
            . 'cleared_at INTEGER NOT NULL DEFAULT 0, '
            . 'PRIMARY KEY (source_key, item_key)'
            . ')',
        );
        $this->database->query(
            'CREATE INDEX IF NOT EXISTS monitor_retention_suppression_source_idx ON '
            . self::SUPPRESSION_TABLE . '(source_key, cleared_at)',
        );

        // Compatibility with the unshipped intermediate shape that stored a
        // URL-scoped tombstone on monitor_collector_state. Preserve those
        // decisions if such a database exists, but do not pretend an erased
        // content hash can be reconstructed: the migrated row protects the
        // original opaque URL key and carries an empty content fingerprint.
        if (
            $schema->tableExists(self::TABLE)
            && $schema->fieldExists(self::TABLE, 'suppressed_at')
            && $schema->fieldExists(self::TABLE, 'suppression_reason')
        ) {
            $rows = $this->database->select(self::TABLE)
                ->fields(self::TABLE, [
                    'source_key', 'item_key', 'item_public_ref', 'suppressed_at', 'suppression_reason',
                ])
                ->execute();
            foreach ($rows as $row) {
                if ((int) $row['suppressed_at'] <= 0) {
                    continue;
                }
                $this->database->insert(self::SUPPRESSION_TABLE)
                    ->values([
                        'source_key' => (string) $row['source_key'],
                        'item_key' => (string) $row['item_key'],
                        'item_public_ref' => (string) $row['item_public_ref'],
                        'fingerprint_salt' => '',
                        'content_fingerprint' => '',
                        'suppression_reason' => (string) $row['suppression_reason'],
                        'suppressed_at' => (int) $row['suppressed_at'],
                        'cleared_at' => 0,
                    ])
                    ->execute();
            }
        }
    }

    /**
     * @return array<string, array{item_key: string, item_public_ref: string, content_hash: string, normalized_bytes: int, absent_runs: int, exclusion_kind: string, exclusion_reason: string, updated_at: int}>
     *   Keyed by item_key.
     */
    public function allForSource(string $sourceKey): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $rows = $this->database->select(self::TABLE)
            ->fields(self::TABLE, [
                'item_key', 'item_public_ref', 'content_hash', 'normalized_bytes',
                'absent_runs', 'exclusion_kind', 'exclusion_reason', 'updated_at',
            ])
            ->condition('source_key', $sourceKey)
            ->execute();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['item_key']] = [
                'item_key' => (string) $row['item_key'],
                'item_public_ref' => (string) $row['item_public_ref'],
                'content_hash' => (string) $row['content_hash'],
                'normalized_bytes' => (int) $row['normalized_bytes'],
                'absent_runs' => (int) $row['absent_runs'],
                'exclusion_kind' => (string) ($row['exclusion_kind'] ?? ''),
                'exclusion_reason' => (string) ($row['exclusion_reason'] ?? ''),
                'updated_at' => (int) $row['updated_at'],
            ];
        }

        return $out;
    }

    /**
     * Find a row on the same source carrying $contentHash, excluding
     * $exceptItemKey and restricted to $candidateKeys.
     *
     * The caller passes only keys **confirmed absent in this same successful
     * crawl**. Without that restriction, identical content at a second *live*
     * URL looks like a move, and two genuinely separate pages are merged into
     * one history — see `PublicSiteCollector::detectMove()`.
     *
     * @param list<string> $candidateKeys
     * @return array{item_key: string, item_public_ref: string}|null
     */
    public function findMoveCandidate(
        string $sourceKey,
        string $contentHash,
        string $exceptItemKey,
        array $candidateKeys,
    ): ?array {
        if (!$this->tableExists() || $candidateKeys === []) {
            return null;
        }

        $rows = $this->database->select(self::TABLE)
            ->fields(self::TABLE, ['item_key', 'item_public_ref'])
            ->condition('source_key', $sourceKey)
            ->condition('content_hash', $contentHash)
            ->execute();

        foreach ($rows as $row) {
            $key = (string) $row['item_key'];
            if ($key !== $exceptItemKey && in_array($key, $candidateKeys, true)) {
                return ['item_key' => $key, 'item_public_ref' => (string) $row['item_public_ref']];
            }
        }

        return null;
    }

    public function exists(string $sourceKey, string $itemKey): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        foreach (
            $this->database->select(self::TABLE)
                ->fields(self::TABLE, ['item_key'])
                ->condition('source_key', $sourceKey)
                ->condition('item_key', $itemKey)
                ->execute() as $ignored
        ) {
            return true;
        }

        return false;
    }

    /**
     * Insert or update the row for an observed, retainable item.
     *
     * Clears `absent_runs` and any exclusion state: seeing real content again
     * is the recovery signal for both.
     */
    public function record(
        string $sourceKey,
        string $itemKey,
        string $itemPublicRef,
        string $contentHash,
        int $normalizedBytes,
        int $now,
    ): void {
        $values = [
            'item_public_ref' => $itemPublicRef,
            'content_hash' => $contentHash,
            'normalized_bytes' => $normalizedBytes,
            'absent_runs' => 0,
            'exclusion_kind' => '',
            'exclusion_reason' => '',
            'updated_at' => $now,
        ];

        if ($this->exists($sourceKey, $itemKey)) {
            $this->database->update(self::TABLE)
                ->fields($values)
                ->condition('source_key', $sourceKey)
                ->condition('item_key', $itemKey)
                ->execute();

            return;
        }

        $this->database->insert(self::TABLE)
            ->values(['source_key' => $sourceKey, 'item_key' => $itemKey, ...$values])
            ->execute();
    }

    /**
     * Record that an item is currently excluded from collection.
     *
     * Stores the **state only**: no body, no hash, no snapshot. Returns true
     * when this is a *transition* into the state, which is the only time the
     * collector emits an event — otherwise a page that stays gated produces one
     * event per run forever, and the timeline becomes noise.
     */
    public function recordExclusion(
        string $sourceKey,
        string $itemKey,
        ExclusionKind $kind,
        string $reason,
        int $now,
    ): bool {
        $existing = $this->allForSource($sourceKey)[$itemKey] ?? null;

        if ($existing === null) {
            // Never seen before and already excluded: remembered so the state
            // is not re-announced, with no content retained.
            $this->database->insert(self::TABLE)
                ->values([
                    'source_key' => $sourceKey,
                    'item_key' => $itemKey,
                    'item_public_ref' => '',
                    'content_hash' => '',
                    'normalized_bytes' => 0,
                    'absent_runs' => 0,
                    'exclusion_kind' => $kind->value,
                    'exclusion_reason' => $reason,
                    'updated_at' => $now,
                ])
                ->execute();

            return true;
        }

        $isTransition = $existing['exclusion_kind'] !== $kind->value;

        // Purge what we already hold (review finding 2). Declining to collect
        // from here on is only half of what an exclusion means. `noindex` is
        // specified as "do not collect or retain this page", and a page that
        // moved behind the portal is material we should never have had — yet
        // the previously stored body sat in the snapshot table for up to 90
        // more days under the ordinary retention timer. That honoured the
        // publisher's instruction forwards and ignored it backwards.
        $this->purgeRetainedContent($sourceKey, $itemKey);

        $this->database->update(self::TABLE)
            ->fields([
                'exclusion_kind' => $kind->value,
                'exclusion_reason' => $reason,
                // Content-derived state goes with the content. A retained hash
                // is a fingerprint of a body we are no longer entitled to hold,
                // and keeping it would also let a later run "detect a change"
                // in a page it must not be reading.
                'content_hash' => '',
                'normalized_bytes' => 0,
                // An excluded page is not an absent page; absence is a separate
                // finding and must not accumulate while we are being told "no".
                'absent_runs' => 0,
                'updated_at' => $now,
            ])
            ->condition('source_key', $sourceKey)
            ->condition('item_key', $itemKey)
            ->execute();

        return $isTransition;
    }

    /**
     * Mark an item as retention-suppressed.
     *
     * Set by redaction, and **never cleared by the collector**. The asymmetry
     * with exclusion is deliberate: lifting an exclusion is an observation
     * about the publisher's site — the gate came off, the `noindex` was removed
     * — whereas lifting a suppression is a decision about our own obligation
     * not to hold this material. The specification defines no automatic path
     * back, so inventing one here would quietly overturn a redaction the next
     * time the page changed.
     *
     * Without this, redaction was durable only until the next crawl: the item
     * kept its `content_hash`, so the following content change re-hashed the
     * body, appended a fresh snapshot and restored the public locator.
     */
    public function suppressRetention(string $sourceKey, string $itemKey, string $reason, int $now): void
    {
        if (!$this->database->schema()->tableExists(self::SUPPRESSION_TABLE)) {
            throw new \LogicException('Suppression storage must be prepared before the redaction transaction starts.');
        }

        $row = $this->allForSource($sourceKey)[$itemKey] ?? null;
        if ($row === null) {
            throw new \RuntimeException(sprintf('Collector state for "%s" was not found.', $itemKey));
        }

        $contentHash = (string) $row['content_hash'];
        $salt = $contentHash === '' ? '' : bin2hex(random_bytes(16));
        $fingerprint = $contentHash === '' ? '' : self::fingerprint($contentHash, $salt);
        $values = [
            'item_public_ref' => (string) $row['item_public_ref'],
            'fingerprint_salt' => $salt,
            'content_fingerprint' => $fingerprint,
            'suppression_reason' => $reason,
            'suppressed_at' => $now,
            'cleared_at' => 0,
        ];

        if ($this->suppressionRow($sourceKey, $itemKey) === null) {
            $this->database->insert(self::SUPPRESSION_TABLE)
                ->values(['source_key' => $sourceKey, 'item_key' => $itemKey, ...$values])
                ->execute();
        } else {
            $this->database->update(self::SUPPRESSION_TABLE)
                ->fields($values)
                ->condition('source_key', $sourceKey)
                ->condition('item_key', $itemKey)
                ->execute();
        }

        $this->database->update(self::TABLE)
            ->fields([
                // Content-derived state goes with the content.
                'content_hash' => '',
                'normalized_bytes' => 0,
                'updated_at' => $now,
            ])
            ->condition('source_key', $sourceKey)
            ->condition('item_key', $itemKey)
            ->execute();
    }

    /** Whether retention is currently suppressed for this item. */
    public function isSuppressed(string $sourceKey, string $itemKey): bool
    {
        $row = $this->suppressionRow($sourceKey, $itemKey);

        return $row !== null && $row['suppressed_at'] > 0 && $row['cleared_at'] === 0;
    }

    /**
     * Match content against every active tombstone for this source.
     *
     * The raw content hash is deliberately not retained by the tombstone. Each
     * row uses a random salt and a domain-specific HMAC. That is enough to
     * recognise the same normalized body at a different URL without turning
     * the suppression table into another copy of collector content state.
     *
     * @return array{item_public_ref: string, suppression_reason: string, fingerprint_salt: string, content_fingerprint: string}|null
     */
    public function activeSuppressionForContent(string $sourceKey, string $contentHash): ?array
    {
        foreach ($this->suppressionRowsForSource($sourceKey) as $row) {
            if ($row['cleared_at'] !== 0 || $row['content_fingerprint'] === '') {
                continue;
            }
            if (hash_equals($row['content_fingerprint'], self::fingerprint($contentHash, $row['fingerprint_salt']))) {
                return [
                    'item_public_ref' => $row['item_public_ref'],
                    'suppression_reason' => $row['suppression_reason'],
                    'fingerprint_salt' => $row['fingerprint_salt'],
                    'content_fingerprint' => $row['content_fingerprint'],
                ];
            }
        }

        return null;
    }

    /**
     * Carry a content-level suppression to another opaque URL key.
     *
     * @param array{item_public_ref: string, suppression_reason: string, fingerprint_salt: string, content_fingerprint: string} $suppression
     */
    public function propagateSuppression(
        string $sourceKey,
        string $itemKey,
        array $suppression,
        int $now,
    ): void {
        if ($this->suppressionRow($sourceKey, $itemKey) !== null) {
            return;
        }

        $this->database->insert(self::SUPPRESSION_TABLE)
            ->values([
                'source_key' => $sourceKey,
                'item_key' => $itemKey,
                'item_public_ref' => (string) $suppression['item_public_ref'],
                'fingerprint_salt' => (string) $suppression['fingerprint_salt'],
                'content_fingerprint' => (string) $suppression['content_fingerprint'],
                'suppression_reason' => (string) $suppression['suppression_reason'],
                'suppressed_at' => $now,
                'cleared_at' => 0,
            ])
            ->execute();
    }

    /** Whether an audited clear is waiting to be reflected by the collector. */
    public function clearedSuppression(string $sourceKey, string $itemKey): bool
    {
        $row = $this->suppressionRow($sourceKey, $itemKey);

        return $row !== null && $row['cleared_at'] > 0;
    }

    /**
     * Lift a suppression. Intended for an audited maintainer action only —
     * nothing in the collector calls this, and nothing should.
     */
    public function clearSuppression(string $sourceKey, string $itemKey, int $now): void
    {
        $row = $this->suppressionRow($sourceKey, $itemKey);
        if ($row === null) {
            return;
        }

        foreach ($this->suppressionRowsForSource($sourceKey) as $candidate) {
            $sameContent = $row['content_fingerprint'] !== ''
                && hash_equals($row['content_fingerprint'], $candidate['content_fingerprint']);
            if ($candidate['item_key'] !== $itemKey && !$sameContent) {
                continue;
            }
            $this->database->update(self::SUPPRESSION_TABLE)
                ->fields(['cleared_at' => $now])
                ->condition('source_key', $sourceKey)
                ->condition('item_key', $candidate['item_key'])
                ->execute();
        }
    }

    /** Remove a consumed, explicitly-cleared tombstone family. */
    public function consumeClearedSuppression(string $sourceKey, string $itemKey): void
    {
        $row = $this->suppressionRow($sourceKey, $itemKey);
        if ($row === null || $row['cleared_at'] === 0) {
            return;
        }

        foreach ($this->suppressionRowsForSource($sourceKey) as $candidate) {
            $sameContent = $row['content_fingerprint'] !== ''
                && hash_equals($row['content_fingerprint'], $candidate['content_fingerprint']);
            if ($candidate['item_key'] !== $itemKey && !$sameContent) {
                continue;
            }
            $this->database->delete(self::SUPPRESSION_TABLE)
                ->condition('source_key', $sourceKey)
                ->condition('item_key', $candidate['item_key'])
                ->execute();
        }
    }

    /**
     * @return array{item_key: string, item_public_ref: string, fingerprint_salt: string, content_fingerprint: string, suppression_reason: string, suppressed_at: int, cleared_at: int}|null
     */
    private function suppressionRow(string $sourceKey, string $itemKey): ?array
    {
        foreach ($this->suppressionRowsForSource($sourceKey) as $row) {
            if ($row['item_key'] === $itemKey) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array{item_key: string, item_public_ref: string, fingerprint_salt: string, content_fingerprint: string, suppression_reason: string, suppressed_at: int, cleared_at: int}>
     */
    private function suppressionRowsForSource(string $sourceKey): array
    {
        if (!$this->database->schema()->tableExists(self::SUPPRESSION_TABLE)) {
            return [];
        }

        $rows = $this->database->select(self::SUPPRESSION_TABLE)
            ->fields(self::SUPPRESSION_TABLE, [
                'item_key', 'item_public_ref', 'fingerprint_salt', 'content_fingerprint',
                'suppression_reason', 'suppressed_at', 'cleared_at',
            ])
            ->condition('source_key', $sourceKey)
            ->execute();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'item_key' => (string) $row['item_key'],
                'item_public_ref' => (string) $row['item_public_ref'],
                'fingerprint_salt' => (string) $row['fingerprint_salt'],
                'content_fingerprint' => (string) $row['content_fingerprint'],
                'suppression_reason' => (string) $row['suppression_reason'],
                'suppressed_at' => (int) $row['suppressed_at'],
                'cleared_at' => (int) $row['cleared_at'],
            ];
        }

        return $out;
    }

    private static function fingerprint(string $contentHash, string $salt): string
    {
        $key = hex2bin($salt);
        if ($key === false) {
            throw new \RuntimeException('The suppression fingerprint salt is malformed.');
        }

        return hash_hmac('sha256', 'monitor-retention-v1:' . $contentHash, $key);
    }

    /**
     * Delete every stored snapshot for one item.
     *
     * Used by exclusion transitions and by redaction. Returns the number of
     * rows removed so callers can report it.
     */
    public function purgeRetainedContent(string $sourceKey, string $itemKey): int
    {
        if (!$this->database->schema()->tableExists(self::SNAPSHOT_TABLE)) {
            return 0;
        }

        $rows = $this->database->select(self::SNAPSHOT_TABLE)
            ->fields(self::SNAPSHOT_TABLE, ['id'])
            ->condition('source_key', $sourceKey)
            ->condition('item_key', $itemKey)
            ->execute();

        $removed = 0;
        foreach ($rows as $row) {
            $this->database->delete(self::SNAPSHOT_TABLE)->condition('id', $row['id'])->execute();
            ++$removed;
        }

        return $removed;
    }

    /**
     * Whether $itemKey is currently recorded as excluded, and how.
     *
     * @return array{kind: string, reason: string}|null
     */
    public function currentExclusion(string $sourceKey, string $itemKey): ?array
    {
        $row = $this->allForSource($sourceKey)[$itemKey] ?? null;
        if ($row === null || $row['exclusion_kind'] === '') {
            return null;
        }

        return ['kind' => $row['exclusion_kind'], 'reason' => $row['exclusion_reason']];
    }

    public function incrementAbsent(string $sourceKey, string $itemKey, int $now): int
    {
        $current = $this->allForSource($sourceKey)[$itemKey]['absent_runs'] ?? 0;
        $next = $current + 1;

        $this->database->update(self::TABLE)
            ->fields(['absent_runs' => $next, 'updated_at' => $now])
            ->condition('source_key', $sourceKey)
            ->condition('item_key', $itemKey)
            ->execute();

        return $next;
    }

    public function clearAbsent(string $sourceKey, string $itemKey, int $now): void
    {
        $this->database->update(self::TABLE)
            ->fields(['absent_runs' => 0, 'updated_at' => $now])
            ->condition('source_key', $sourceKey)
            ->condition('item_key', $itemKey)
            ->execute();
    }

    /**
     * Move an item's identity from one key to another, atomically.
     *
     * A rename must leave **one** active key per `public_ref`. Leaving the old
     * key in place would let it go absent on later runs and publish a
     * disappearance for an item being served happily at its new URL.
     */
    public function moveIdentity(
        string $sourceKey,
        string $fromItemKey,
        string $toItemKey,
        string $publicRef,
        string $contentHash,
        int $normalizedBytes,
        int $now,
    ): void {
        $this->database->delete(self::TABLE)
            ->condition('source_key', $sourceKey)
            ->condition('item_key', $fromItemKey)
            ->execute();

        // Carry the snapshot history across, so a rename does not lose the
        // item's record of what it used to say.
        if ($this->database->schema()->tableExists(self::SNAPSHOT_TABLE)) {
            $this->database->update(self::SNAPSHOT_TABLE)
                ->fields(['item_key' => $toItemKey])
                ->condition('source_key', $sourceKey)
                ->condition('item_key', $fromItemKey)
                ->execute();
        }

        $this->record($sourceKey, $toItemKey, $publicRef, $contentHash, $normalizedBytes, $now);
    }

    /**
     * Active item keys mapped to $publicRef. More than one is an invariant
     * violation; the disappearance path asserts on it.
     *
     * @return list<string>
     */
    public function keysForPublicRef(string $sourceKey, string $publicRef): array
    {
        if (!$this->tableExists() || $publicRef === '') {
            return [];
        }

        $keys = [];
        foreach ($this->allForSource($sourceKey) as $row) {
            if ($row['item_public_ref'] === $publicRef) {
                $keys[] = $row['item_key'];
            }
        }

        return $keys;
    }

    // ------------------------------------------------------------------
    // Snapshot history — three per item, 2 MB each, 90 days
    // ------------------------------------------------------------------

    /**
     * Append a snapshot and trim the item's history to the newest three.
     *
     * Skipped when the newest retained snapshot already carries this hash:
     * re-storing identical bytes would evict real history for nothing.
     */
    public function appendSnapshot(string $sourceKey, string $itemKey, string $contentHash, string $snapshot, int $now): void
    {
        $existing = $this->snapshotsFor($sourceKey, $itemKey);
        if (($existing[0]['content_hash'] ?? null) === $contentHash) {
            return;
        }

        $this->database->insert(self::SNAPSHOT_TABLE)
            ->values([
                'source_key' => $sourceKey,
                'item_key' => $itemKey,
                'content_hash' => $contentHash,
                'snapshot' => $snapshot,
                'snapshot_bytes' => strlen($snapshot),
                'taken_at' => $now,
            ])
            ->execute();

        $this->trimSnapshots($sourceKey, $itemKey);
    }

    /**
     * Newest first.
     *
     * @return list<array{id: int, content_hash: string, snapshot_bytes: int, taken_at: int}>
     */
    public function snapshotsFor(string $sourceKey, string $itemKey): array
    {
        if (!$this->database->schema()->tableExists(self::SNAPSHOT_TABLE)) {
            return [];
        }

        $rows = $this->database->select(self::SNAPSHOT_TABLE)
            ->fields(self::SNAPSHOT_TABLE, ['id', 'content_hash', 'snapshot_bytes', 'taken_at'])
            ->condition('source_key', $sourceKey)
            ->condition('item_key', $itemKey)
            ->orderBy('taken_at', 'DESC')
            ->execute();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'content_hash' => (string) $row['content_hash'],
                'snapshot_bytes' => (int) $row['snapshot_bytes'],
                'taken_at' => (int) $row['taken_at'],
            ];
        }

        // Stable ordering when several snapshots share a timestamp (tests, and
        // a fast upstream edit): newest id wins.
        usort($out, static fn (array $a, array $b): int => [$b['taken_at'], $b['id']] <=> [$a['taken_at'], $a['id']]);

        return $out;
    }

    /** Keep only the newest {@see MAX_SNAPSHOTS_PER_ITEM}. */
    private function trimSnapshots(string $sourceKey, string $itemKey): void
    {
        foreach (array_slice($this->snapshotsFor($sourceKey, $itemKey), self::MAX_SNAPSHOTS_PER_ITEM) as $stale) {
            $this->database->delete(self::SNAPSHOT_TABLE)->condition('id', $stale['id'])->execute();
        }
    }

    /**
     * Delete snapshots older than the 90-day retention window.
     *
     * Deletes the row rather than blanking it: the side table still holds the
     * hash and counters, so the collector keeps its memory while the retained
     * copy of someone else's page is genuinely gone.
     *
     * @return int Snapshots deleted.
     */
    public function pruneExpiredSnapshots(int $now): int
    {
        if (!$this->database->schema()->tableExists(self::SNAPSHOT_TABLE)) {
            return 0;
        }

        $cutoff = $now - (self::SNAPSHOT_RETENTION_DAYS * 86_400);

        $stale = $this->database->select(self::SNAPSHOT_TABLE)
            ->fields(self::SNAPSHOT_TABLE, ['id'])
            ->condition('taken_at', $cutoff, '<')
            ->execute();

        $ids = [];
        foreach ($stale as $row) {
            $ids[] = (int) $row['id'];
        }
        foreach ($ids as $id) {
            $this->database->delete(self::SNAPSHOT_TABLE)->condition('id', $id)->execute();
        }

        return count($ids);
    }

    public function itemKeyForPublicRef(string $sourceKey, string $publicRef): ?string
    {
        return $this->keysForPublicRef($sourceKey, $publicRef)[0] ?? null;
    }

    /**
     * The next opaque public ref for a source — an incrementing per-source
     * counter, never a hash or a URL, so nothing about the upstream item can be
     * recovered from it.
     *
     * Derived from the highest assigned number rather than a row count, because
     * exclusion-only rows carry no ref and must not consume one.
     */
    public function nextPublicRef(string $sourceKey): string
    {
        $highest = 0;
        foreach ($this->allForSource($sourceKey) as $row) {
            if (preg_match('/-(\d+)$/', $row['item_public_ref'], $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return sprintf('%s-%04d', $sourceKey, $highest + 1);
    }
}
