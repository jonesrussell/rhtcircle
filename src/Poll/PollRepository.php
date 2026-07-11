<?php

declare(strict_types=1);

namespace App\Poll;

use Waaseyaa\Database\DatabaseInterface;

/**
 * All reads and writes for the poll system, against the Circle's own database.
 *
 * A vote is never a per-voter row: casting a vote increments poll_option.votes
 * in place, so there is nothing resembling a ballot to store, export, or leak.
 * Anti-abuse (poll_rate_limit) is a separate table keyed only by a salted IP
 * hash and a timestamp, never joined to a poll or option, so throttling can
 * never be traced back to how anyone voted.
 */
final class PollRepository
{
    /**
     * Max votes from one ip_hash inside the window before we refuse. Soft: a
     * shared household or band-office network can plausibly send more than a
     * handful of legitimate votes.
     */
    private const RATE_MAX = 10;
    private const RATE_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $hashSecret,
    ) {}

    // ---- Setup -------------------------------------------------------------

    /**
     * Idempotently ensure a poll and its options exist, in the given order.
     * Safe to call on every boot; only inserts when the slug is absent, so
     * editing $labels here after launch does not resurrect or reorder a poll
     * that already has votes.
     *
     * @param list<string> $labels
     */
    public function ensurePoll(string $slug, string $question, array $labels): void
    {
        if ($this->findPoll($slug) !== null) {
            return;
        }

        $this->db->query(
            'INSERT INTO ' . PollSchema::TABLE_POLL . ' (slug, question, active, created_at) VALUES (?, ?, 1, ?)',
            [$slug, $question, $this->now()],
        );

        $poll = $this->findPoll($slug);
        if ($poll === null) {
            return;
        }

        foreach ($labels as $position => $label) {
            $this->db->query(
                'INSERT INTO ' . PollSchema::TABLE_OPTION . ' (poll_id, position, label, votes) VALUES (?, ?, ?, 0)',
                [(int) $poll['id'], $position, $label],
            );
        }
    }

    /** @return array<string, mixed>|null */
    public function findPoll(string $slug): ?array
    {
        foreach ($this->db->query('SELECT * FROM ' . PollSchema::TABLE_POLL . ' WHERE slug = ?', [$slug]) as $row) {
            return $row;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function findActivePoll(string $slug): ?array
    {
        $poll = $this->findPoll($slug);

        return ($poll !== null && (int) $poll['active'] === 1) ? $poll : null;
    }

    // ---- Reading -----------------------------------------------------------

    /** @return list<array{id: int, label: string, votes: int}> */
    public function options(int $pollId): array
    {
        $out = [];
        foreach ($this->db->query(
            'SELECT id, label, votes FROM ' . PollSchema::TABLE_OPTION . ' WHERE poll_id = ? ORDER BY position ASC',
            [$pollId],
        ) as $row) {
            $out[] = ['id' => (int) $row['id'], 'label' => (string) $row['label'], 'votes' => (int) $row['votes']];
        }

        return $out;
    }

    public function isValidOption(int $pollId, int $optionId): bool
    {
        foreach ($this->options($pollId) as $option) {
            if ($option['id'] === $optionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Results as percentages, for display after voting. Rounds to whole
     * percent; the option(s) with the largest remainder absorb any rounding
     * gap so the bars always sum to 100 once there is at least one vote.
     *
     * @return array{total: int, options: list<array{id: int, label: string, votes: int, percent: int}>}
     */
    public function results(int $pollId): array
    {
        $options = $this->options($pollId);
        $total = array_sum(array_column($options, 'votes'));

        if ($total === 0) {
            return [
                'total' => 0,
                'options' => array_map(static fn (array $o) => $o + ['percent' => 0], $options),
            ];
        }

        $withPercent = [];
        $remainders = [];
        $assigned = 0;
        foreach ($options as $i => $option) {
            $exact = $option['votes'] * 100 / $total;
            $floor = (int) floor($exact);
            $withPercent[$i] = $option + ['percent' => $floor];
            $remainders[$i] = $exact - $floor;
            $assigned += $floor;
        }

        arsort($remainders);
        $remaining = 100 - $assigned;
        foreach (array_keys($remainders) as $i) {
            if ($remaining <= 0) {
                break;
            }
            $withPercent[$i]['percent']++;
            $remaining--;
        }

        return ['total' => $total, 'options' => array_values($withPercent)];
    }

    // ---- Voting --------------------------------------------------------------

    /** Increments the chosen option's tally. No row is created for the vote. */
    public function castVote(int $optionId): void
    {
        $this->db->query(
            'UPDATE ' . PollSchema::TABLE_OPTION . ' SET votes = votes + 1 WHERE id = ?',
            [$optionId],
        );
    }

    // ---- Anti-abuse ------------------------------------------------------

    /** True when this ip_hash has cast too many votes in the window (any poll). */
    public function tooManyFromIp(?string $ip): bool
    {
        $ipHash = $this->hash($ip);
        if ($ipHash === null) {
            return false;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RATE_WINDOW_SECONDS);

        foreach ($this->db->query(
            'SELECT COUNT(*) AS c FROM ' . PollSchema::TABLE_RATE_LIMIT . ' WHERE ip_hash = ? AND created_at > ?',
            [$ipHash, $cutoff],
        ) as $row) {
            return (int) $row['c'] >= self::RATE_MAX;
        }

        return false;
    }

    /**
     * Records one rate-limit tick and opportunistically prunes rows outside
     * the window, so this bookkeeping table cannot grow without bound.
     */
    public function recordAttempt(?string $ip): void
    {
        $ipHash = $this->hash($ip);
        if ($ipHash === null) {
            return;
        }
        $this->db->query(
            'INSERT INTO ' . PollSchema::TABLE_RATE_LIMIT . ' (ip_hash, created_at) VALUES (?, ?)',
            [$ipHash, $this->now()],
        );
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RATE_WINDOW_SECONDS);
        $this->db->query('DELETE FROM ' . PollSchema::TABLE_RATE_LIMIT . ' WHERE created_at <= ?', [$cutoff]);
    }

    // ---- Internals -------------------------------------------------------

    private function hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash('sha256', $this->hashSecret . '|' . $value);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
