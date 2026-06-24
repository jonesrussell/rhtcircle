<?php

declare(strict_types=1);

namespace App\Contact;

use Waaseyaa\Database\DatabaseInterface;

/**
 * The contact-message table on the Circle's own database.
 *
 * Operational (non-entity) table, so it uses DatabaseInterface directly, same as
 * the petition tables. The framework has no migration CLI, so it is ensured at
 * boot (idempotent: it only creates the table when absent).
 *
 * Stores only what a reply needs: a name, an email, an optional self-declared
 * "I am a" kind, and the message. IP and user-agent are kept ONLY as salted,
 * one-way hashes for rate limiting, never displayed and never reversible.
 */
final class ContactSchema
{
    public const string TABLE = 'contact_message';

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
                'name' => ['type' => 'varchar', 'length' => 120, 'not null' => true],
                // Required here so the Circle can reply.
                'email' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                // Self-declared, optional: "member", "supporter", or "other".
                'kind' => ['type' => 'varchar', 'length' => 16, 'not null' => true, 'default' => 'other'],
                'message' => ['type' => 'text', 'not null' => true],
                'created_at' => ['type' => 'varchar', 'length' => 19, 'not null' => true],
                // When an admin has seen it (reserved; nullable).
                'read_at' => ['type' => 'varchar', 'length' => 19],
                // Salted one-way hashes, kept ONLY for rate limiting.
                'ip_hash' => ['type' => 'varchar', 'length' => 64],
                'user_agent_hash' => ['type' => 'varchar', 'length' => 64],
            ],
            'primary key' => ['id'],
            'indexes' => [
                // Per-ip_hash rate-limiting window.
                'idx_cm_ip' => ['ip_hash', 'created_at'],
                // Admin listing, newest first.
                'idx_cm_created' => ['created_at'],
            ],
        ]);
    }
}
