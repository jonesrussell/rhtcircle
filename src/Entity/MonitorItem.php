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
 * A public page or document tracked over time (spec §3.2).
 *
 * `item_key` is deliberately absent: the collector's stable identity key, the
 * content hash and the normalized snapshot all live in the non-entity side
 * table (spec §3.6), so there is no internal identifier on this entity at all
 * and nothing for a projection to leak by accident. Everything that relates to
 * an item relates by `public_ref`, an opaque per-source counter.
 */
#[ContentEntityType(id: 'monitor_item', label: 'Monitor item', storageBackend: PrimaryStorageBackend::SQL_COLUMN)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class MonitorItem extends ContentEntityBase
{
    #[Field(label: 'Source key', required: true, settings: ['weight' => 0], stored: FieldStorage::Column, read: FieldReadLevel::Public, indexed: true)]
    public string $source_key = '';

    /**
     * The only identifier ever rendered or related to. Opaque (`p-1`, `p-2`, …),
     * assigned at first sight, unique with source_key, and carrying no preimage.
     */
    #[Field(label: 'Public ref', required: true, settings: ['weight' => 1], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $public_ref = '';

    #[Field(required: false, label: 'Title', settings: ['weight' => 2], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(required: false, label: 'Public URL', settings: ['weight' => 3], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $public_url = '';

    #[Field(required: false, label: 'Doc kind', description: 'page | document | unknown.', settings: ['weight' => 4], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $doc_kind = 'page';

    /**
     * A derived projection of the newest monitor_event, stored as a column
     * because it is a primary listing facet. It MUST be written in the same
     * save as the event that causes it: a projection that can disagree with its
     * own event log is the dual-state bug this codebase has been bitten by.
     */
    #[Field(required: false, label: 'Change status', description: 'new | unchanged | changed | disappeared | reappeared.', settings: ['weight' => 5], stored: FieldStorage::Column, read: FieldReadLevel::Public, indexed: true)]
    public string $change_status = 'new';

    #[Field(required: false, type: 'integer', label: 'First seen', settings: ['weight' => 6], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $first_seen = 0;

    #[Field(required: false, type: 'integer', label: 'Last seen', settings: ['weight' => 7], stored: FieldStorage::Column, read: FieldReadLevel::Public, indexed: true)]
    public int $last_seen = 0;

    #[Field(required: false, type: 'integer', label: 'Changed at', settings: ['weight' => 8], stored: FieldStorage::Column, read: FieldReadLevel::Public, indexed: true)]
    public int $changed_at = 0;

    #[Field(required: false, type: 'integer', label: 'Disappeared at', settings: ['weight' => 9], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $disappeared_at = 0;

    #[Field(required: false, type: 'integer', label: 'Event count', settings: ['weight' => 10], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $event_count = 0;

}
