<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldStorage;

/**
 * Manually reviewed metadata about updates published INSIDE the members portal,
 * recorded only when a member deliberately supplies it (spec §7.2).
 *
 * This is the one path by which portal-adjacent information enters the app, and
 * it is a human one: a member supplies it to a maintainer out of band, and the
 * maintainer reviews it against the app's rules 1 to 4 before entering it
 * through the CLI. There is no public submission form.
 *
 * The hard constraints are enforced by the SHAPE of this type rather than at
 * render time, which is why the following fields do not exist and must never be
 * added:
 *   - no URL field        -> no direct file link, nothing to strip
 *   - no attachment/blob/body/excerpt -> no raw document can be stored
 *   - no hash, no identifier -> nothing composes into a confirmation oracle or
 *                               an enumeration of portal contents
 *
 * The reviewer is responsible for confirming no member PII, no signature and no
 * password appears in any supplied field. The tests assert the shape; only a
 * human can assert the content.
 */
#[ContentEntityType(id: 'portal_update_note', label: 'Portal update note')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title_supplied')]
final class PortalUpdateNote extends ContentEntityBase
{
    /** Rendered at month precision. */
    #[Field(required: false, type: 'integer', label: 'Supplied on', settings: ['weight' => 0], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $supplied_on = 0;

    /** The date the update itself carries, if any. */
    #[Field(required: false, type: 'integer', label: 'Official date', settings: ['weight' => 1], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $official_date = 0;

    /** As a member chose to describe it. Never a filename, never copied from a document. */
    #[Field(label: 'Title supplied', required: true, settings: ['weight' => 2], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $title_supplied = '';

    #[Field(required: false, type: 'text', label: 'Summary', settings: ['weight' => 3], stored: FieldStorage::Data, read: FieldReadLevel::Public)]
    public string $summary = '';

    /** A role, never a person. */
    #[Field(label: 'Supplied by role', required: true, settings: ['weight' => 4], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $supplied_by_role = '';

    /** Internal: the reviewer's own notes. */
    #[Field(required: false, type: 'text', label: 'Review note', settings: ['weight' => 5], stored: FieldStorage::Data, read: FieldReadLevel::Internal)]
    public string $review_note = '';
}
