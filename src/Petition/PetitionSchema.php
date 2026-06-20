<?php

declare(strict_types=1);

namespace App\Petition;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Creates the petition tables on demand.
 *
 * The framework has no migration CLI, so the tables are ensured at boot,
 * guarded by tableExists() (the same pattern as AnalyticsSchema). These are
 * first-party operational record tables and use DatabaseInterface directly.
 *
 * SOVEREIGNTY NOTE (OCAP): every column here lives only in the Circle's own SQLite
 * database on Circle-controlled hardware. We collect the MINIMUM needed to run a
 * petition and nothing more. There is deliberately NO band registry number, NO
 * date of birth, and NO government identifier. The only identifiers retained
 * are a name and an email (the email lets us de-duplicate and lets a person
 * remove their own signature) plus salted one-way hashes of IP and user-agent
 * used solely for rate-limiting.
 *
 * The `verified` flag and `verify_token` column are retained for forward
 * compatibility: signatures currently count on creation (verified = 1), but a
 * future sovereign mailer can re-introduce a double opt-in by inserting
 * verified = 0 and confirming via the token. Today the token's live use is the
 * one-click "remove my signature" link.
 */
final class PetitionSchema
{
    public const TABLE_CAMPAIGN = 'petition_campaign';
    public const TABLE_SIGNATURE = 'petition_signature';

    public function __construct(private readonly DatabaseInterface $db) {}

    public function ensure(): void
    {
        $this->ensureCampaignTable();
        $this->ensureSignatureTable();
        $this->ensureSignatureColumns();
        $this->ensureCampaignColumns();
    }

    /**
     * Additive column migrations for the campaign table: an aggregate count of
     * signatures collected on paper and physically handed in, kept beside the
     * online rows so the public total reflects real-world support too. Guarded
     * by PRAGMA so it is idempotent and safe on every boot. No personal data is
     * stored here, only a count and a dated, public provenance note.
     */
    private function ensureCampaignColumns(): void
    {
        if (!$this->db->schema()->tableExists(self::TABLE_CAMPAIGN)) {
            return;
        }

        // Never let a migration hiccup take down app boot (see the signature note).
        try {
            $have = [];
            foreach ($this->db->query('PRAGMA table_info(' . self::TABLE_CAMPAIGN . ')') as $row) {
                $have[(string) $row['name']] = true;
            }

            if (!isset($have['paper_count'])) {
                $this->db->query(
                    'ALTER TABLE ' . self::TABLE_CAMPAIGN
                    . ' ADD COLUMN paper_count int NOT NULL DEFAULT 0',
                );
            }
            if (!isset($have['paper_note'])) {
                $this->db->query(
                    'ALTER TABLE ' . self::TABLE_CAMPAIGN . ' ADD COLUMN paper_note varchar(255)',
                );
            }
            if (!isset($have['paper_updated_at'])) {
                $this->db->query(
                    'ALTER TABLE ' . self::TABLE_CAMPAIGN . ' ADD COLUMN paper_updated_at varchar(19)',
                );
            }
            if (!isset($have['online_base'])) {
                $this->db->query(
                    'ALTER TABLE ' . self::TABLE_CAMPAIGN
                    . ' ADD COLUMN online_base int NOT NULL DEFAULT 0',
                );
            }
        } catch (\Throwable) {
            // swallow — see ensureSignatureColumns note
        }
    }

    /**
     * Additive column migrations for the signature table (createTable only fires
     * for a fresh DB; an existing prod table needs ALTER). Each step is guarded
     * by PRAGMA table_info so it is idempotent and safe to run on every boot.
     */
    private function ensureSignatureColumns(): void
    {
        if (!$this->db->schema()->tableExists(self::TABLE_SIGNATURE)) {
            return;
        }

        // Never let a migration hiccup take down app boot; a failure surfaces
        // later at sign time (the INSERT references the column) rather than 500ing
        // every page.
        try {
            $have = [];
            foreach ($this->db->query('PRAGMA table_info(' . self::TABLE_SIGNATURE . ')') as $row) {
                $have[(string) $row['name']] = true;
            }

            // "Include my name on the letter to Chief and Council" — separate from
            // show_name_publicly (public display). Defaults to 0 (count me only).
            if (!isset($have['include_name_on_letter'])) {
                $this->db->query(
                    'ALTER TABLE ' . self::TABLE_SIGNATURE
                    . ' ADD COLUMN include_name_on_letter int NOT NULL DEFAULT 0',
                );
            }
        } catch (\Throwable) {
            // swallow — see note above
        }
    }

    private function ensureCampaignTable(): void
    {
        $schema = $this->db->schema();
        if ($schema->tableExists(self::TABLE_CAMPAIGN)) {
            return;
        }

        $schema->createTable(self::TABLE_CAMPAIGN, [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'slug' => ['type' => 'varchar', 'length' => 100, 'not null' => true],
                'title' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'the_ask' => ['type' => 'varchar', 'length' => 500, 'not null' => true],
                'recipient' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'active' => ['type' => 'int', 'not null' => true, 'default' => 1],
                'created_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
                // Signatures collected on paper and physically handed in (an
                // aggregate count only), plus a dated public provenance note.
                'paper_count' => ['type' => 'int', 'not null' => true, 'default' => 0],
                'paper_note' => ['type' => 'varchar', 'length' => 255],
                'paper_updated_at' => ['type' => 'varchar', 'length' => 19],
                // Code-managed base of online sign-ons carried over from before
                // this campaign moved to the Circle's own site. Aggregate only.
                'online_base' => ['type' => 'int', 'not null' => true, 'default' => 0],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_pc_slug' => ['slug'],
                'idx_pc_active' => ['active'],
            ],
        ]);
    }

    private function ensureSignatureTable(): void
    {
        $schema = $this->db->schema();
        if ($schema->tableExists(self::TABLE_SIGNATURE)) {
            return;
        }

        $schema->createTable(self::TABLE_SIGNATURE, [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'campaign_id' => ['type' => 'int', 'not null' => true],
                // Minimum personal data: a name and an email only.
                'name' => ['type' => 'varchar', 'length' => 120, 'not null' => true],
                'email' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                // Self-declared, non-verified: "member" or "supporter". Never a
                // band number or any proof of membership.
                'member_flag' => ['type' => 'varchar', 'length' => 16, 'not null' => true],
                'comment' => ['type' => 'varchar', 'length' => 500],
                'show_name_publicly' => ['type' => 'int', 'not null' => true, 'default' => 0],
                // "Include my name on the letter to Chief and Council" (private
                // to the letter / admin export; distinct from public display).
                'include_name_on_letter' => ['type' => 'int', 'not null' => true, 'default' => 0],
                'consent' => ['type' => 'int', 'not null' => true, 'default' => 0],
                // Set to 1 on creation today (opt-in deferred); kept so a future
                // mailer can gate counting on a confirmed email instead.
                'verified' => ['type' => 'int', 'not null' => true, 'default' => 0],
                // Per-signature secret. Live use: the one-click "remove my
                // signature" link. Also usable as a future confirm token.
                'verify_token' => ['type' => 'varchar', 'length' => 64, 'not null' => true],
                'created_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
                'deleted_at' => ['type' => 'varchar', 'length' => 19],
                // Salted one-way hashes, kept ONLY for rate-limiting. They cannot
                // be reversed to an IP/user-agent and are never displayed.
                'ip_hash' => ['type' => 'varchar', 'length' => 64],
                'user_agent_hash' => ['type' => 'varchar', 'length' => 64],
            ],
            'primary key' => ['id'],
            'indexes' => [
                // Drives the public verified, non-deleted count.
                'idx_ps_count' => ['campaign_id', 'verified', 'deleted_at'],
                'idx_ps_token' => ['verify_token'],
                // Resolve an existing signature for an email (resend / re-sign).
                'idx_ps_email' => ['campaign_id', 'email'],
                // Per-ip_hash rate limiting window.
                'idx_ps_ip' => ['ip_hash', 'created_at'],
            ],
        ]);
    }
}
