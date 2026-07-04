<?php

declare(strict_types=1);

namespace App\Signup;

use Waaseyaa\Database\DatabaseInterface;

/**
 * All reads and writes for the member-owned email list, against the Circle's
 * own database. Mirrors PetitionRepository: dedup by email (re-signup restores
 * a removed row rather than creating a duplicate), a per-row remove_token for
 * the one-click unsubscribe link (no login needed), salted one-way IP/UA
 * hashes kept only for rate limiting.
 *
 * COLLECT-ONLY: TODO(send) — when send infrastructure exists, either rely on
 * the stored express consent (consent_text_version + consent_at) or run a
 * one-time confirmation pass across existing rows first. No mailer is wired
 * here; store() never sends anything.
 */
final class SignupRepository
{
    private const int RATE_MAX = 5;
    private const int RATE_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $hashSecret,
    ) {}

    /**
     * Store (or re-confirm, if previously removed) an express-consent signup.
     * Returns the remove token (used to build the one-click unsubscribe link).
     */
    public function store(
        string $email,
        ?string $firstName,
        ?string $nation,
        string $consentTextVersion,
        ?string $ip,
        ?string $userAgent,
    ): string {
        $token = bin2hex(random_bytes(32));
        $now = $this->now();
        $existing = $this->findByEmail($email);

        if ($existing !== null) {
            $this->db->query(
                'UPDATE ' . SignupSchema::TABLE . ' SET'
                . ' first_name = ?, nation = ?, consent = 1, consent_text_version = ?, consent_at = ?,'
                . ' status = ?, removed_at = NULL, remove_token = ?, ip_hash = ?, user_agent_hash = ?'
                . ' WHERE id = ?',
                [
                    $firstName, $nation, $consentTextVersion, $now,
                    'confirmed-by-consent', $token, $this->hash($ip), $this->hash($userAgent),
                    (int) $existing['id'],
                ],
            );

            return $token;
        }

        $this->db->query(
            'INSERT INTO ' . SignupSchema::TABLE
            . ' (email, first_name, nation, consent, consent_text_version, consent_at, status, created_at,'
            . ' remove_token, ip_hash, user_agent_hash)'
            . ' VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)',
            [
                $email, $firstName, $nation, $consentTextVersion, $now,
                'confirmed-by-consent', $now, $token, $this->hash($ip), $this->hash($userAgent),
            ],
        );

        return $token;
    }

    /**
     * Remove (suppress) a row by its one-click token. The row is retained with
     * status=removed for suppression (never re-contacted, never re-counted),
     * never deleted outright, mirroring the petition's soft-delete tombstone.
     *
     * @return bool true if a row was found and removed (or already removed)
     */
    public function removeByToken(string $token): bool
    {
        $row = $this->findByToken($token);
        if ($row === null) {
            return false;
        }
        if ($row['status'] !== 'removed') {
            $this->db->query(
                'UPDATE ' . SignupSchema::TABLE . ' SET status = ?, removed_at = ? WHERE id = ?',
                ['removed', $this->now(), (int) $row['id']],
            );
        }

        return true;
    }

    /** Same removal, keyed by email instead of token (e.g. an admin action). */
    public function removeByEmail(string $email): bool
    {
        $row = $this->findByEmail($email);
        if ($row === null) {
            return false;
        }
        if ($row['status'] !== 'removed') {
            $this->db->query(
                'UPDATE ' . SignupSchema::TABLE . ' SET status = ?, removed_at = ? WHERE id = ?',
                ['removed', $this->now(), (int) $row['id']],
            );
        }

        return true;
    }

    public function tooManyFromIp(?string $ip): bool
    {
        $ipHash = $this->hash($ip);
        if ($ipHash === null) {
            return false;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RATE_WINDOW_SECONDS);
        foreach ($this->db->query(
            'SELECT COUNT(*) AS c FROM ' . SignupSchema::TABLE . ' WHERE ip_hash = ? AND created_at > ?',
            [$ipHash, $cutoff],
        ) as $row) {
            return (int) ($row['c'] ?? 0) >= self::RATE_MAX;
        }

        return false;
    }

    /** Count of currently-confirmed (non-removed) rows, for the gated admin. */
    public function confirmedCount(): int
    {
        foreach ($this->db->query(
            "SELECT COUNT(*) AS c FROM " . SignupSchema::TABLE . " WHERE status = 'confirmed-by-consent'",
        ) as $row) {
            return (int) ($row['c'] ?? 0);
        }

        return 0;
    }

    /** @return array<string, mixed>|null */
    private function findByEmail(string $email): ?array
    {
        foreach ($this->db->query(
            'SELECT * FROM ' . SignupSchema::TABLE . ' WHERE email = ? LIMIT 1',
            [$email],
        ) as $row) {
            return $row;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function findByToken(string $token): ?array
    {
        foreach ($this->db->query(
            'SELECT * FROM ' . SignupSchema::TABLE . ' WHERE remove_token = ? LIMIT 1',
            [$token],
        ) as $row) {
            return $row;
        }

        return null;
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
