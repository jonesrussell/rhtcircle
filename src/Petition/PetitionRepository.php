<?php

declare(strict_types=1);

namespace App\Petition;

use Waaseyaa\Database\DatabaseInterface;

/**
 * All reads and writes for the petition system, against the Circle's own database.
 *
 * Non-entity operational tables (see PetitionSchema), so this uses
 * DatabaseInterface directly. Every method here is the single, audited place a
 * given query lives; controllers never build SQL.
 */
final class PetitionRepository
{
    /** Max new signatures from one ip_hash inside the window before we refuse. */
    private const RATE_MAX = 5;
    private const RATE_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $hashSecret,
    ) {}

    // ---- Campaigns -------------------------------------------------------

    /**
     * Idempotently ensure a campaign exists with the given slug. Safe to call
     * on every boot; it only inserts when the slug is absent.
     */
    public function ensureCampaign(string $slug, string $title, string $ask, string $recipient): void
    {
        if ($this->findCampaign($slug) !== null) {
            return;
        }
        $this->createCampaign($slug, $title, $ask, $recipient);
    }

    public function createCampaign(string $slug, string $title, string $ask, string $recipient): void
    {
        $this->db->query(
            'INSERT INTO ' . PetitionSchema::TABLE_CAMPAIGN
            . ' (slug, title, the_ask, recipient, active, created_at) VALUES (?, ?, ?, ?, 1, ?)',
            [$slug, $title, $ask, $recipient, $this->now()],
        );
    }

    /**
     * Reconcile display metadata without changing the campaign identity or any
     * signatures. Used for non-substantive corrections such as a canonical URL
     * move in the ask text. Returns false when the campaign is absent or already
     * matches, so it is safe to call on every boot.
     */
    public function setCampaignDetails(string $slug, string $title, string $ask, string $recipient): bool
    {
        $campaign = $this->findCampaign($slug);
        if ($campaign === null) {
            return false;
        }
        if ((string) $campaign['title'] === $title
            && (string) $campaign['the_ask'] === $ask
            && (string) $campaign['recipient'] === $recipient) {
            return false;
        }
        $this->db->query(
            'UPDATE ' . PetitionSchema::TABLE_CAMPAIGN . ' SET title = ?, the_ask = ?, recipient = ? WHERE slug = ?',
            [$title, $ask, $recipient, $slug],
        );

        return true;
    }

    public function setCampaignActive(string $slug, bool $active): void
    {
        $this->db->query(
            'UPDATE ' . PetitionSchema::TABLE_CAMPAIGN . ' SET active = ? WHERE slug = ?',
            [$active ? 1 : 0, $slug],
        );
    }

    /**
     * Set the count of signatures collected on paper and handed in for a
     * campaign, with a short public provenance note (where and when). This is an
     * aggregate count only; no paper signer's personal data is stored. Idempotent:
     * it only writes when the count or note actually changes, so it is safe to
     * reconcile from code on every boot.
     */
    public function setPaperCount(string $slug, int $count, string $note): bool
    {
        $campaign = $this->findCampaign($slug);
        if ($campaign === null) {
            return false;
        }
        $count = max(0, $count);
        if ((int) ($campaign['paper_count'] ?? 0) === $count && (string) ($campaign['paper_note'] ?? '') === $note) {
            return false;
        }
        $this->db->query(
            'UPDATE ' . PetitionSchema::TABLE_CAMPAIGN
            . ' SET paper_count = ?, paper_note = ?, paper_updated_at = ? WHERE slug = ?',
            [$count, $note, $this->now(), $slug],
        );

        return true;
    }

    /**
     * Set a code-managed base of online sign-ons carried over from before this
     * campaign moved to the Circle's own site. Added to the live verified count
     * and the paper count for the public total. Aggregate only, no PII.
     * Idempotent: writes only when the count changes.
     */
    public function setOnlineBase(string $slug, int $count): bool
    {
        $campaign = $this->findCampaign($slug);
        if ($campaign === null) {
            return false;
        }
        $count = max(0, $count);
        if ((int) ($campaign['online_base'] ?? 0) === $count) {
            return false;
        }
        $this->db->query(
            'UPDATE ' . PetitionSchema::TABLE_CAMPAIGN . ' SET online_base = ? WHERE slug = ?',
            [$count, $slug],
        );

        return true;
    }

    /** @return array<string, mixed>|null */
    public function findCampaign(string $slug): ?array
    {
        foreach ($this->db->query(
            'SELECT * FROM ' . PetitionSchema::TABLE_CAMPAIGN . ' WHERE slug = ?',
            [$slug],
        ) as $row) {
            return $row;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function findActiveCampaign(string $slug): ?array
    {
        $campaign = $this->findCampaign($slug);

        return ($campaign !== null && (int) $campaign['active'] === 1) ? $campaign : null;
    }

    /** @return list<array<string, mixed>> */
    public function listCampaigns(): array
    {
        $out = [];
        foreach ($this->db->query('SELECT * FROM ' . PetitionSchema::TABLE_CAMPAIGN . ' ORDER BY created_at DESC') as $row) {
            $row['verified_count'] = $this->verifiedCount((int) $row['id']);
            $out[] = $row;
        }

        return $out;
    }

    // ---- Signing ---------------------------------------------------------

    /**
     * Record (or update) a signature and return its manage token so the caller
     * can offer a personal "remove my signature" link. Enforces one row per
     * (campaign, email): signing again with the same email updates the row in
     * place, restores it if it had been removed, and issues a fresh token.
     *
     * Signatures count as soon as they are stored (verified = 1). The original
     * design used a double opt-in email to set verified; that step is deferred,
     * so the column stays for forward-compatibility but is set on creation. The
     * `verified` flag and `verify_token` column thus remain in the schema and a
     * sovereign (self-hosted / Canadian) mailer can re-introduce opt-in later
     * by inserting verified = 0 and confirming via the token.
     *
     * @return array{status: 'new'|'updated', token: string}
     */
    public function recordSignature(
        int $campaignId,
        string $name,
        string $email,
        string $memberFlag,
        ?string $comment,
        bool $showNamePublicly,
        bool $includeNameOnLetter,
        bool $consent,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $token = bin2hex(random_bytes(32));

        // Dedup by (campaign, email) only when an email is given. Email is
        // optional on some campaigns; without one we cannot identify a prior
        // row, so each signature is a fresh INSERT.
        $existing = $email !== '' ? $this->findSignatureByEmail($campaignId, $email) : null;

        if ($existing !== null) {
            // Update in place: new token, restore from any soft-delete, refresh.
            $this->db->query(
                'UPDATE ' . PetitionSchema::TABLE_SIGNATURE . ' SET'
                . ' name = ?, member_flag = ?, comment = ?, show_name_publicly = ?, include_name_on_letter = ?, consent = ?,'
                . ' verified = 1, verify_token = ?, deleted_at = NULL, ip_hash = ?, user_agent_hash = ?'
                . ' WHERE id = ?',
                [
                    $name, $memberFlag, $comment, $showNamePublicly ? 1 : 0, $includeNameOnLetter ? 1 : 0, $consent ? 1 : 0,
                    $token, $this->hash($ip), $this->hash($userAgent), (int) $existing['id'],
                ],
            );

            return ['status' => 'updated', 'token' => $token];
        }

        $this->db->query(
            'INSERT INTO ' . PetitionSchema::TABLE_SIGNATURE
            . ' (campaign_id, name, email, member_flag, comment, show_name_publicly, include_name_on_letter, consent,'
            . ' verified, verify_token, created_at, ip_hash, user_agent_hash)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
            [
                $campaignId, $name, $email, $memberFlag, $comment, $showNamePublicly ? 1 : 0, $includeNameOnLetter ? 1 : 0,
                $consent ? 1 : 0, $token, $this->now(), $this->hash($ip), $this->hash($userAgent),
            ],
        );

        return ['status' => 'new', 'token' => $token];
    }

    /**
     * Soft-delete a signature from its token (the one-click remove link). The
     * row is retained as tombstone (deleted_at set) so a removal can be audited
     * and the count is correct; it no longer counts and is never displayed.
     *
     * @return array<string, mixed>|null the campaign row, or null if not found
     */
    public function remove(string $token): ?array
    {
        $signature = $this->findSignatureByToken($token);
        if ($signature === null) {
            return null;
        }

        if ($signature['deleted_at'] === null) {
            $this->db->query(
                'UPDATE ' . PetitionSchema::TABLE_SIGNATURE . ' SET deleted_at = ? WHERE id = ?',
                [$this->now(), (int) $signature['id']],
            );
        }

        return $this->findCampaignById((int) $signature['campaign_id']);
    }

    // ---- Public read surface --------------------------------------------

    /**
     * The public support total for a campaign: a carried-over online base, plus
     * verified online signatures, plus signatures collected on paper and handed
     * in. Takes the campaign row so callers that already loaded it avoid a second
     * query.
     *
     * @param array<string, mixed> $campaign
     */
    public function publicCount(array $campaign): int
    {
        return $this->verifiedCount((int) $campaign['id'])
            + (int) ($campaign['paper_count'] ?? 0)
            + (int) ($campaign['online_base'] ?? 0);
    }

    /**
     * The total/online/paper breakdown shown wherever the site mentions a
     * signature count (the sign-up counter, the awaiting-council page, the
     * community hub card). Single source so none of those can drift from the
     * database independently, the way a hand-typed number in a template did
     * (see the July 2026 stale-caption incident).
     *
     * @param array<string, mixed> $campaign
     *
     * @return array{total: int, online: int, paper: int}
     */
    public function signatureBreakdown(array $campaign): array
    {
        return [
            'total' => $this->publicCount($campaign),
            'online' => $this->verifiedCount((int) $campaign['id']),
            'paper' => (int) ($campaign['paper_count'] ?? 0),
        ];
    }

    public function verifiedCount(int $campaignId): int
    {
        foreach ($this->db->query(
            'SELECT COUNT(*) AS c FROM ' . PetitionSchema::TABLE_SIGNATURE
            . ' WHERE campaign_id = ? AND verified = 1 AND deleted_at IS NULL',
            [$campaignId],
        ) as $row) {
            return (int) $row['c'];
        }

        return 0;
    }

    /**
     * Recent verified signers who opted into a public display, newest first.
     * Returns ONLY a privacy-safe display name (first name + last initial).
     * Email is never included here.
     *
     * @return list<array{name: string}>
     */
    public function recentPublicSupporters(int $campaignId, int $limit = 8): array
    {
        $out = [];
        foreach ($this->db->query(
            'SELECT name FROM ' . PetitionSchema::TABLE_SIGNATURE
            . ' WHERE campaign_id = ? AND verified = 1 AND deleted_at IS NULL AND show_name_publicly = 1'
            . ' ORDER BY id DESC LIMIT ?',
            [$campaignId, max(1, $limit)],
        ) as $row) {
            $out[] = ['name' => self::publicName((string) $row['name'])];
        }

        return $out;
    }

    /**
     * Verified, non-deleted rows for CSV export (admin only). Includes email
     * because the export is the audited artefact presented to Council; it is
     * gated behind admin auth and never exposed publicly.
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(int $campaignId): array
    {
        $out = [];
        foreach ($this->db->query(
            'SELECT name, email, member_flag, comment, show_name_publicly, include_name_on_letter, created_at'
            . ' FROM ' . PetitionSchema::TABLE_SIGNATURE
            . ' WHERE campaign_id = ? AND verified = 1 AND deleted_at IS NULL ORDER BY id ASC',
            [$campaignId],
        ) as $row) {
            $out[] = $row;
        }

        return $out;
    }

    // ---- Anti-abuse ------------------------------------------------------

    /** True when this ip_hash has created too many signatures in the window. */
    public function tooManyFromIp(?string $ip): bool
    {
        $ipHash = $this->hash($ip);
        if ($ipHash === null) {
            return false;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RATE_WINDOW_SECONDS);

        foreach ($this->db->query(
            'SELECT COUNT(*) AS c FROM ' . PetitionSchema::TABLE_SIGNATURE
            . ' WHERE ip_hash = ? AND created_at > ?',
            [$ipHash, $cutoff],
        ) as $row) {
            return (int) $row['c'] >= self::RATE_MAX;
        }

        return false;
    }

    // ---- Internals -------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function findSignatureByEmail(int $campaignId, string $email): ?array
    {
        foreach ($this->db->query(
            'SELECT * FROM ' . PetitionSchema::TABLE_SIGNATURE . ' WHERE campaign_id = ? AND email = ?',
            [$campaignId, $email],
        ) as $row) {
            return $row;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function findSignatureByToken(string $token): ?array
    {
        foreach ($this->db->query(
            'SELECT * FROM ' . PetitionSchema::TABLE_SIGNATURE . ' WHERE verify_token = ?',
            [$token],
        ) as $row) {
            return $row;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function findCampaignById(int $id): ?array
    {
        foreach ($this->db->query(
            'SELECT * FROM ' . PetitionSchema::TABLE_CAMPAIGN . ' WHERE id = ?',
            [$id],
        ) as $row) {
            return $row;
        }

        return null;
    }

    /** Salted one-way hash; null in, null out (so absent IPs stay absent). */
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

    /** First name + last initial, e.g. "Russell J." Privacy-safe for display. */
    public static function publicName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
        if ($parts === []) {
            return 'A supporter';
        }
        $first = $parts[0];
        if (count($parts) === 1) {
            return $first;
        }
        $lastInitial = mb_strtoupper(mb_substr(end($parts), 0, 1));

        return $first . ' ' . $lastInitial . '.';
    }
}
