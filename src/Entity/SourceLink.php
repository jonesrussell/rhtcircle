<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * A citation: a labelled link to a public source, attached to a piece of content.
 *
 * A small, reusable supporting type (pass 3 introduces it for myth_entry; nation,
 * land_project, and explainer reuse it later). The `owner` token names the
 * content it belongs to as "<entity_type>:<slug>" (for example
 * "myth_entry:legal-fees"), following the by-slug reference convention the Anokii
 * graph already uses. The `label` is translatable; the `url`, owner, and weight
 * are not (a source's address is the same in every language).
 */
#[ContentEntityType(id: 'source_link', label: 'Source link')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'label', langcode: 'langcode', default_langcode: 'default_langcode')]
final class SourceLink extends ContentEntityBase
{
    #[Field(label: 'Owner', description: 'The content this source belongs to, as "<entity_type>:<slug>".', required: true, settings: ['weight' => 0])]
    public string $owner = '';

    #[Field(label: 'Label', required: true, translatable: true, settings: ['weight' => 1])]
    public string $label = '';

    #[Field(label: 'URL', required: true, settings: ['weight' => 2])]
    public string $url = '';

    #[Field(type: 'integer', label: 'Weight', description: 'Display order within the owner, low to high.', settings: ['weight' => 3])]
    public int $weight = 0;
}
