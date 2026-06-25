<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * A "what you have heard, and what the record says" entry, as managed content.
 *
 * The first managed content type in the rhtcircle CMS consolidation (pass 3
 * pilot). Replaces the hand-authored App\Content\MythEntries array for the
 * /myth-versus-record page. The prose fields are translatable (English plus
 * Anishinaabemowin) through the framework translation subsystem; the slug and
 * display weight are not. Sources are separate App\Entity\SourceLink rows keyed
 * by this entry's owner token, so a citation's label can be translated while its
 * URL is not.
 */
#[ContentEntityType(id: 'myth_entry', label: 'Myth versus record')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'question', langcode: 'langcode', default_langcode: 'default_langcode')]
final class MythEntry extends ContentEntityBase
{
    #[Field(label: 'Slug', description: 'Stable identifier (the select key used by the myth component).', required: true, settings: ['weight' => 0])]
    public string $slug = '';

    #[Field(label: 'Question', required: true, translatable: true, settings: ['weight' => 1])]
    public string $question = '';

    #[Field(type: 'text', label: 'Answer', translatable: true, settings: ['weight' => 2])]
    public string $answer = '';

    #[Field(type: 'text', label: 'Record', translatable: true, settings: ['weight' => 3])]
    public string $record = '';

    #[Field(type: 'text', label: 'Takeaway', translatable: true, settings: ['weight' => 4])]
    public string $takeaway = '';

    #[Field(type: 'integer', label: 'Weight', description: 'Display order, low to high.', settings: ['weight' => 5])]
    public int $weight = 0;
}
