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
 * The current-issues tracker: a member-facing issue, hand-authored (spec §3.4).
 *
 * Never auto-created. Machine observation proposes; a member decides. Seeded
 * only from facts and asks already published on the Circle's existing Sagamok
 * disclosure pages.
 */
#[ContentEntityType(id: 'monitor_issue', label: 'Monitor issue', storageBackend: PrimaryStorageBackend::SQL_COLUMN)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class MonitorIssue extends ContentEntityBase
{
    #[Field(label: 'Slug', required: true, settings: ['weight' => 0], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $slug = '';

    /** Framed as a question or a neutral statement of what is outstanding. */
    #[Field(label: 'Title', required: true, settings: ['weight' => 1], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $title = '';

    /**
     * The issue lifecycle state.
     *
     * Deliberately NOT named `status`. Spec §3.4 calls this field `status`,
     * but §6.2 rule 3 forbids any monitor type from declaring a `status`
     * field at all, because `WorkflowVisibility::isEntityPublic()` keys on
     * exactly that name and a truthy value would open the `/api/discovery/*`
     * routes. The two clauses conflict; the safety rule wins, so the field
     * carries the same semantics under an unambiguous name.
     */
    #[Field(label: 'Issue state', description: 'open | awaiting_response | partly_answered | resolved | withdrawn.', required: true, settings: ['weight' => 2], stored: FieldStorage::Column, read: FieldReadLevel::Public, indexed: true)]
    public string $issue_state = 'open';

    #[Field(required: false, type: 'integer', label: 'Opened at', settings: ['weight' => 3], stored: FieldStorage::Column, read: FieldReadLevel::Public, indexed: true)]
    public int $opened_at = 0;

    #[Field(required: false, type: 'integer', label: 'Status changed at', settings: ['weight' => 4], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $status_changed_at = 0;

    /** Required when status = resolved. */
    #[Field(required: false, type: 'integer', label: 'Closed at', settings: ['weight' => 5], stored: FieldStorage::Column, read: FieldReadLevel::Public, indexed: true)]
    public int $closed_at = 0;

    #[Field(required: false, type: 'text', label: 'Summary', settings: ['weight' => 6], stored: FieldStorage::Data, read: FieldReadLevel::Public)]
    public string $summary = '';

    /** The specific ask, rendered beside the official updates so members judge. */
    #[Field(required: false, type: 'text', label: 'What is asked', settings: ['weight' => 7], stored: FieldStorage::Data, read: FieldReadLevel::Public)]
    public string $what_is_asked = '';

    /** Article slugs, comma separated. Links to node/article records. */
    #[Field(required: false, type: 'text', label: 'Related article slugs', settings: ['weight' => 8], stored: FieldStorage::Data, read: FieldReadLevel::Public)]
    public string $related_article_slugs = '';

    /** Opaque monitor_item public refs, comma separated. Never item keys or row ids. */
    #[Field(required: false, type: 'text', label: 'Related item public refs', settings: ['weight' => 9], stored: FieldStorage::Data, read: FieldReadLevel::Public)]
    public string $related_item_public_refs = '';

    /**
     * Internal editorial triage state, NOT a listing facet and never rendered.
     *
     * A public list sorted by an editorial severity, with a public opened_at
     * beside it, composes into an automatically escalating countdown clock
     * against an office, generated forever with no author taking responsibility
     * for the claim. Render opened_at and let the reader do the arithmetic.
     */
    #[Field(required: false, label: 'Severity', description: 'information | concern | urgent.', settings: ['weight' => 10], stored: FieldStorage::Data, read: FieldReadLevel::Internal)]
    public string $severity = 'information';
}
