<?php

declare(strict_types=1);

namespace App\Monitor;

use Waaseyaa\Database\DatabaseInterface;

/**
 * The collector's side table (spec §3.6) — hashes, identity keys, snapshots and
 * the absence counter.
 *
 * **Not an entity, deliberately.** It has no independent lifecycle, is never
 * rendered and is never related to, which is exactly the case where the app's
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

    /** Approved retention limits (spec §0). */
    public const int SNAPSHOT_RETENTION_DAYS = 90;
    public const int MAX_SNAPSHOTS_PER_ITEM = 3;

    public function __construct(private readonly DatabaseInterface $database) {}

    /**
     * Create the table if absent. Called by the collector rather than schema
     * sync, because this is not an entity type and `db:init` does not know it.
     */
    public function ensureTable(): void
    {
        $schema = $this->database->schema();
        if ($schema->tableExists(self::TABLE)) {
            return;
        }

        $this->database->query(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'source_key TEXT NOT NULL, '
            . 'item_key TEXT NOT NULL, '
            . 'item_public_ref TEXT NOT NULL, '
            . 'content_hash TEXT NOT NULL, '
            . 'normalized_bytes INTEGER NOT NULL DEFAULT 0, '
            . 'snapshot BLOB, '
            . 'snapshot_taken_at INTEGER, '
            . 'absent_runs INTEGER NOT NULL DEFAULT 0, '
            . 'updated_at INTEGER NOT NULL, '
            . 'PRIMARY KEY (source_key, item_key)'
            . ')',
        );
        // The collector scans by source every run, and prunes by snapshot age.
        $this->database->query(
            'CREATE INDEX IF NOT EXISTS monitor_collector_state_source_idx ON ' . self::TABLE . '(source_key)',
        );
        $this->database->query(
            'CREATE INDEX IF NOT EXISTS monitor_collector_state_snapshot_taken_idx ON '
            . self::TABLE . '(snapshot_taken_at)',
        );
    }

    /**
     * @return array<string, array{item_key: string, item_public_ref: string, content_hash: string, normalized_bytes: int, absent_runs: int, snapshot_taken_at: ?int, updated_at: int}>
     *   Keyed by item_key.
     */
    public function allForSource(string $sourceKey): array
    {
        $rows = $this->database->select(self::TABLE)
            ->fields(self::TABLE, [
                'item_key', 'item_public_ref', 'content_hash',
                'normalized_bytes', 'absent_runs', 'snapshot_taken_at', 'updated_at',
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
                'snapshot_taken_at' => $row['snapshot_taken_at'] === null ? null : (int) $row['snapshot_taken_at'],
                'updated_at' => (int) $row['updated_at'],
            ];
        }

        return $out;
    }

    /**
     * Find an existing row on the same source carrying $contentHash, excluding
     * $exceptItemKey.
     *
     * This backs the near-duplicate guard (spec §4.2): a URL rename otherwise
     * produces a spurious "document disappeared" plus a spurious "new document"
     * in the same run — the kind of false alarm that would discredit the
     * dashboard faster than missing a real change.
     *
     * @return array{item_key: string, item_public_ref: string}|null
     */
    public function findByContentHash(string $sourceKey, string $contentHash, string $exceptItemKey): ?array
    {
        $rows = $this->database->select(self::TABLE)
            ->fields(self::TABLE, ['item_key', 'item_public_ref'])
            ->condition('source_key', $sourceKey)
            ->condition('content_hash', $contentHash)
            ->execute();

        foreach ($rows as $row) {
            if ((string) $row['item_key'] !== $exceptItemKey) {
                return [
                    'item_key' => (string) $row['item_key'],
                    'item_public_ref' => (string) $row['item_public_ref'],
                ];
            }
        }

        return null;
    }

    public function exists(string $sourceKey, string $itemKey): bool
    {
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
     * Insert or update the row for an observed item, resetting `absent_runs`.
     *
     * $snapshot is stored only when supplied; passing null keeps whatever the
     * row already holds, so a metadata-only change does not consume one of the
     * three retained snapshots.
     */
    public function record(
        string $sourceKey,
        string $itemKey,
        string $itemPublicRef,
        string $contentHash,
        int $normalizedBytes,
        ?string $snapshot,
        int $now,
    ): void {
        $existing = $this->exists($sourceKey, $itemKey);

        $values = [
            'item_public_ref' => $itemPublicRef,
            'content_hash' => $contentHash,
            'normalized_bytes' => $normalizedBytes,
            'absent_runs' => 0,
            'updated_at' => $now,
        ];
        if ($snapshot !== null) {
            $values['snapshot'] = $snapshot;
            $values['snapshot_taken_at'] = $now;
        }

        if ($existing) {
            $this->database->update(self::TABLE)
                ->fields($values)
                ->condition('source_key', $sourceKey)
                ->condition('item_key', $itemKey)
                ->execute();

            return;
        }

        $this->database->insert(self::TABLE)
            ->values([
                'source_key' => $sourceKey,
                'item_key' => $itemKey,
                ...$values,
                'snapshot' => $values['snapshot'] ?? null,
                'snapshot_taken_at' => $values['snapshot_taken_at'] ?? null,
            ])
            ->execute();
    }

    /**
     * Increment and return the consecutive-absence counter for an item.
     *
     * The collector requires **two** consecutive absent runs before reporting a
     * removal (spec §4.3 step 7), so a single upstream timeout is never
     * published as "the Nation took this down".
     */
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
     * Drop snapshot bodies older than the 90-day retention window, keeping the
     * row (its hash and counters are still the collector's memory).
     *
     * @return int Rows whose snapshot was cleared.
     */
    public function pruneExpiredSnapshots(int $now): int
    {
        $cutoff = $now - (self::SNAPSHOT_RETENTION_DAYS * 86_400);

        $stale = $this->database->select(self::TABLE)
            ->fields(self::TABLE, ['source_key', 'item_key'])
            ->condition('snapshot_taken_at', $cutoff, '<')
            ->execute();

        $cleared = 0;
        foreach ($stale as $row) {
            ++$cleared;
            $this->database->update(self::TABLE)
                ->fields(['snapshot' => null, 'snapshot_taken_at' => null])
                ->condition('source_key', (string) $row['source_key'])
                ->condition('item_key', (string) $row['item_key'])
                ->execute();
        }

        return $cleared;
    }

    /**
     * Resolve an opaque public ref back to its identity key.
     *
     * One-directional by design: the mapping lives here so the ref has
     * somewhere to be resolved **without** the key ever appearing on an entity.
     */
    public function itemKeyForPublicRef(string $sourceKey, string $publicRef): ?string
    {
        foreach (
            $this->database->select(self::TABLE)
                ->fields(self::TABLE, ['item_key'])
                ->condition('source_key', $sourceKey)
                ->condition('item_public_ref', $publicRef)
                ->execute() as $row
        ) {
            return (string) $row['item_key'];
        }

        return null;
    }

    /**
     * The next opaque public ref for a source — an incrementing per-source
     * counter, never a hash or a URL, so nothing about the upstream item can be
     * recovered from it.
     */
    public function nextPublicRef(string $sourceKey): string
    {
        $count = count($this->allForSource($sourceKey));

        return sprintf('%s-%04d', $sourceKey, $count + 1);
    }
}
