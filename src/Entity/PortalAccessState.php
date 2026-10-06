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
 * The portal status statement: an append-only record of MANUAL, independent
 * verifications (spec §7.1).
 *
 * Nothing automated ever writes this. There is no collector, no scheduled task,
 * no HTTP client and no credential configuration for the members portal, and
 * this type deliberately declares no field that could hold a locator, a hash,
 * an archive timestamp, a signed URL, a document or member PII. The
 * prohibitions of spec §7 are met by the ABSENCE of the code and the fields,
 * not by a runtime check that could be misconfigured.
 *
 * The rendered statement claims only the access state that was independently
 * verified, and the date. It does NOT claim that any historical exposure has
 * been remediated, or that anything is or is not present anywhere: publishing
 * an unverified all-clear about members' data would be the specific act the
 * existing disclosure pages hold the Nation accountable for.
 */
#[ContentEntityType(id: 'portal_access_state', label: 'Portal access state', storageBackend: PrimaryStorageBackend::SQL_COLUMN)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'state')]
final class PortalAccessState extends ContentEntityBase
{
    /** The date of the manual check. Rendered at MONTH precision only. */
    #[Field(required: false, type: 'integer', label: 'Verified on', settings: ['weight' => 0], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $verified_on = 0;

    #[Field(label: 'State', description: 'access_controlled | not_access_controlled | unknown.', required: true, settings: ['weight' => 1], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $state = 'access_controlled';

    #[Field(required: false, type: 'text', label: 'Statement', settings: ['weight' => 2], stored: FieldStorage::Data, read: FieldReadLevel::Public)]
    public string $statement = '';

    /** A role, never a person (the app's hard rule 3). */
    #[Field(label: 'Verified by role', required: true, settings: ['weight' => 3], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $verified_by_role = '';

    /**
     * Internal so that no description of a checking technique is ever public.
     * Readable only through the audited CLI report (spec §6.4).
     */
    #[Field(required: false, type: 'text', label: 'Method note', settings: ['weight' => 4], stored: FieldStorage::Data, read: FieldReadLevel::Internal)]
    public string $method_note = '';
}
