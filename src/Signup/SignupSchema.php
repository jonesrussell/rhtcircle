<?php

declare(strict_types=1);

namespace App\Signup;

use Waaseyaa\Database\DatabaseInterface;

/**
 * The member-owned email distribution list, COLLECT-ONLY for now (no sending
 * infra exists yet; see working/cc-prompt-rhtcircle-list.md). Operational
 * (non-entity) table, same as contact_message / petition_signature: ensured
 * idempotently at boot, no migration CLI in this framework.
 *
 * Single opt-in with express consent (double opt-in deferred until send infra
 * exists): the checkbox text shown at submit time is stored verbatim-versioned
 * (consent_text_version) with a timestamp (consent_at) as the CASL consent
 * proof, replacing the confirm-click double-opt-in proof for now. Minimal data
 * only: email, optional first name, optional nation/community. IP/user-agent
 * are kept ONLY as salted one-way hashes for rate limiting, same as contact
 * and petition.
 */
final class SignupSchema
{
    public const string TABLE = 'list_signup';

    public function __construct(private readonly DatabaseInterface $db) {}

    public function ensure(): void
    {
        $schema = $this->db->schema();
        if ($schema->tableExists(self::TABLE)) {
            return;
        }

        $schema->createTable(self::TABLE, [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'email' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'first_name' => ['type' => 'varchar', 'length' => 120],
                'nation' => ['type' => 'varchar', 'length' => 120],
                // Express consent given at submit time, and exactly which checkbox
                // text they saw + when, as the CASL proof (TODO(send): when send
                // infra exists, either rely on this stored consent or run a
                // one-time confirmation pass first).
                'consent' => ['type' => 'int', 'not null' => true, 'default' => 0],
                'consent_text_version' => ['type' => 'varchar', 'length' => 32, 'not null' => true],
                'consent_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
                // confirmed-by-consent | removed
                'status' => ['type' => 'varchar', 'length' => 24, 'not null' => true, 'default' => 'confirmed-by-consent'],
                'created_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
                'removed_at' => ['type' => 'varchar', 'length' => 19],
                // Per-row secret, same live use as petition's verify_token: the
                // one-click "remove me" link, so removal never requires a login.
                'remove_token' => ['type' => 'varchar', 'length' => 64, 'not null' => true],
                'ip_hash' => ['type' => 'varchar', 'length' => 64],
                'user_agent_hash' => ['type' => 'varchar', 'length' => 64],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_ls_email' => ['email'],
                'idx_ls_ip' => ['ip_hash', 'created_at'],
                'idx_ls_status' => ['status'],
                'idx_ls_token' => ['remove_token'],
            ],
        ]);
    }
}
