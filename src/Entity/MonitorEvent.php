<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Storage\PrimaryStorageBackend;
use Waaseyaa\Field\FieldStorage;

/**
 * The append-only change log: one row per observed transition (spec §3.3).
 *
 * Never updated and never deleted, with exactly one exception: the operator
 * redaction of spec §3.7, which sets redacted_at and a fixed-enum reason. The
 * row is retained as a stub so the log cannot silently develop holes, and every
 * listing filters redacted rows out.
 *
 * Hash pairs are deliberately NOT stored here. A change is evidenced by the
 * event existing plus the side-table history; publishing hashes buys a reader
 * nothing and the habit is worth not forming.
 */
#[ContentEntityType(id: 'monitor_event', label: 'Monitor event', storageBackend: PrimaryStorageBackend::SQL_COLUMN)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'event_type')]
final class MonitorEvent extends ContentEntityBase
{
    #[Field(label: 'Source key', required: true, settings: ['weight' => 0], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $source_key = '';

    /** Relates to monitor_item.public_ref. Never an item_key, never a row id. */
    #[Field(label: 'Item public ref', required: true, settings: ['weight' => 1], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $item_public_ref = '';

    #[Field(label: 'Event type', description: 'appeared | content_changed | metadata_changed | disappeared | reappeared | became_gated.', required: true, settings: ['weight' => 2], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $event_type = '';

    /** Observation time, which is not the same as any date the source claims. */
    #[Field(required: false, type: 'integer', label: 'Observed at', settings: ['weight' => 3], stored: FieldStorage::Column, read: FieldReadLevel::Public, indexed: true)]
    public int $observed_at = 0;

    /** The date the source itself publishes, when it publishes one. Nullable (0 = none). */
    #[Field(required: false, type: 'integer', label: 'Effective at', settings: ['weight' => 4], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $effective_at = 0;

    /** `direct_fetch` is the only permitted value: no archive or search-index provenance exists in this feature. */
    #[Field(required: false, label: 'Evidence kind', settings: ['weight' => 5], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $evidence_kind = 'direct_fetch';

    #[Field(required: false, label: 'Evidence URL', description: 'The public URL fetched. Public because it is public.', settings: ['weight' => 6], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $evidence_url = '';

    #[Field(required: false, type: 'integer', label: 'Evidence captured at', settings: ['weight' => 7], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $evidence_captured_at = 0;

    /**
     * True for events written by the very first run against a source. The
     * dashboard renders these as "First observed by this monitor", never as
     * proof that the Nation just published something.
     */
    #[Field(required: false, type: 'boolean', label: 'Baseline', settings: ['weight' => 8], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public bool $is_baseline = false;

    /** Operator redaction (spec §3.7). 0 = not redacted. */
    #[Field(required: false, type: 'integer', label: 'Redacted at', settings: ['weight' => 9], stored: FieldStorage::Column, read: FieldReadLevel::Public, indexed: true)]
    public int $redacted_at = 0;

    /** A fixed enum label, never free text. See MonitorRedactionReason. */
    #[Field(required: false, label: 'Redaction reason', settings: ['weight' => 10], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $redaction_reason = '';

    /** Internal: operator diagnostics, readable only through the audited CLI report. */
    #[Field(required: false, type: 'text', label: 'Notes', settings: ['weight' => 11], stored: FieldStorage::Data, read: FieldReadLevel::Internal)]
    public string $notes = '';
}
