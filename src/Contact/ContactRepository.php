<?php

declare(strict_types=1);

namespace App\Contact;

use Waaseyaa\Database\DatabaseInterface;

/**
 * All reads and writes for the contact form, against the Circle's own database.
 *
 * Operational table (see ContactSchema), so this uses DatabaseInterface directly
 * and is the single audited place each query lives; the controller builds no SQL.
 * Mirrors the petition repository: salted one-way hashes for IP/user-agent kept
 * only for rate limiting.
 */
final class ContactRepository
{
    /** Max new messages from one ip_hash inside the window before we refuse. */
    private const int RATE_MAX = 5;
    private const int RATE_WINDOW_SECONDS = 3600;

    /** Allowed self-declared kinds; anything else is stored as "other". */
    private const array KINDS = ['member', 'supporter', 'other'];

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $hashSecret,
    ) {}

    /** Store a message; returns the new row id. */
    public function store(
        string $name,
        string $email,
        string $kind,
        string $message,
        ?string $ip,
        ?string $userAgent,
    ): int {
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'other';
        $this->db->query(
            'INSERT INTO ' . ContactSchema::TABLE
            . ' (name, email, kind, message, created_at, ip_hash, user_agent_hash)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$name, $email, $kind, $message, $this->now(), $this->hash($ip), $this->hash($userAgent)],
        );

        foreach ($this->db->query('SELECT MAX(id) AS id FROM ' . ContactSchema::TABLE) as $row) {
            return (int) ($row['id'] ?? 0);
        }

        return 0;
    }

    public function tooManyFromIp(?string $ip): bool
    {
        $ipHash = $this->hash($ip);
        if ($ipHash === null) {
            return false;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RATE_WINDOW_SECONDS);
        foreach ($this->db->query(
            'SELECT COUNT(*) AS c FROM ' . ContactSchema::TABLE . ' WHERE ip_hash = ? AND created_at > ?',
            [$ipHash, $cutoff],
        ) as $row) {
            return (int) ($row['c'] ?? 0) >= self::RATE_MAX;
        }

        return false;
    }

    /**
     * Recent messages for the gated admin list (newest first). Includes the
     * email so an admin can reply; the admin surface is noindex and permission
     * gated.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $out = [];
        foreach ($this->db->query(
            'SELECT id, name, email, kind, message, created_at FROM ' . ContactSchema::TABLE
            . ' ORDER BY id DESC LIMIT ' . $limit,
        ) as $row) {
            $out[] = $row;
        }

        return $out;
    }

    public function count(): int
    {
        foreach ($this->db->query('SELECT COUNT(*) AS c FROM ' . ContactSchema::TABLE) as $row) {
            return (int) ($row['c'] ?? 0);
        }

        return 0;
    }

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
