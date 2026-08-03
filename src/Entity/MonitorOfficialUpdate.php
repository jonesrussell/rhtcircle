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
 * What the Nation actually said, publicly (spec §3.5).
 *
 * Requires a PUBLIC source URL. A source that is not public is recorded by
 * label with an empty URL. This is deliberately a different type from
 * portal_update_note: their provenance rules differ, and conflating them would
 * let a portal-sourced item inherit this one's assumptions.
 */
#[ContentEntityType(id: 'monitor_official_update', label: 'Monitor official update', storageBackend: PrimaryStorageBackend::SQL_COLUMN)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'source_label')]
final class MonitorOfficialUpdate extends ContentEntityBase
{
    #[Field(label: 'Issue slug', required: true, settings: ['weight' => 0], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $issue_slug = '';

    #[Field(required: false, type: 'integer', label: 'Published at', settings: ['weight' => 1], stored: FieldStorage::Column, read: FieldReadLevel::Public, indexed: true)]
    public int $published_at = 0;

    #[Field(label: 'Source label', description: 'For example "Council minutes, June 3 2026".', required: true, settings: ['weight' => 2], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $source_label = '';

    /** A public URL only; empty when the source is not public. */
    #[Field(required: false, label: 'Source URL', settings: ['weight' => 3], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $source_url = '';

    #[Field(required: false, type: 'text', label: 'Summary', settings: ['weight' => 4], stored: FieldStorage::Data, read: FieldReadLevel::Public)]
    public string $summary = '';

    /**
     * Internal editorial judgement, NOT a listing facet and never rendered.
     *
     * A machine-readable, sortable public scorecard of "no" against the Nation's
     * official statements is a conclusion about conduct, not a sourced fact
     * framed as a question, and it breaks the app's hard rule 2. Render the ask
     * and the official update side by side and let the member judge.
     */
    #[Field(required: false, label: 'Answers ask', description: 'yes | partly | no | unclear.', settings: ['weight' => 5], stored: FieldStorage::Data, read: FieldReadLevel::Internal)]
    public string $answers_ask = 'unclear';
}
