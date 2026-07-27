<?php

declare(strict_types=1);

namespace App\Cms;

use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldStorage;

/**
 * Bundle fields for revisionable node/article records.
 *
 * Only community_slug is column-backed because it participates in a Listing
 * filter. The editorial payload stays in the revisioned _data snapshot.
 */
final class ArticleFields
{
    public const string BUNDLE = 'article';

    /**
     * @return list<FieldDefinition>
     */
    public static function definitions(): array
    {
        return [
            self::column('community_slug', 'string', 'Community', 'Nation slug used by community article listings.', 40),
            self::data('kicker', 'string', 'Kicker', 41),
            self::data('deck', 'text', 'Deck', 42),
            self::data('summary', 'text', 'Card summary', 43),
            self::data('author', 'string', 'Author', 44),
            self::data('date_display', 'string', 'Publication date', 45),
            self::data('date_iso', 'string', 'Publication date (ISO)', 46),
            self::data('section', 'string', 'Section', 47),
            self::data('action_label', 'string', 'Card action label', 48),
            self::data('og_description', 'text', 'Social description', 49),
            self::data('social_image', 'string', 'Social image URL', 50),
            self::data('social_image_alt', 'text', 'Social image alt text', 51),
            self::data('social_image_width', 'integer', 'Social image width', 52),
            self::data('social_image_height', 'integer', 'Social image height', 53),
            self::data('hero_src', 'string', 'Hero image', 54),
            self::data('hero_width', 'integer', 'Hero width', 55),
            self::data('hero_height', 'integer', 'Hero height', 56),
            self::data('hero_alt', 'text', 'Hero alt text', 57),
            self::data('hero_caption', 'text', 'Hero caption', 58),
            self::data('body_html', 'text', 'Article body', 60),
            self::data('sidebar_html', 'text', 'Article sidebar', 61),
            self::data('sources_html', 'text', 'Sources and limits', 62),
            self::data('revision_log', 'text', 'Revision log', 63, FieldReadLevel::Internal),
        ];
    }

    private static function data(
        string $name,
        string $type,
        string $label,
        int $weight,
        FieldReadLevel $read = FieldReadLevel::Public,
    ): FieldDefinition {
        return new FieldDefinition(
            name: $name,
            type: $type,
            settings: ['weight' => $weight, 'widget' => $type === 'text' ? 'textarea' : 'text'],
            targetEntityTypeId: 'node',
            targetBundle: self::BUNDLE,
            revisionable: true,
            label: $label,
            stored: FieldStorage::Data,
            read: $read,
        );
    }

    private static function column(
        string $name,
        string $type,
        string $label,
        string $description,
        int $weight,
    ): FieldDefinition {
        return (new FieldDefinition(
            name: $name,
            type: $type,
            settings: ['weight' => $weight],
            targetEntityTypeId: 'node',
            targetBundle: self::BUNDLE,
            revisionable: true,
            label: $label,
            description: $description,
            required: true,
            read: FieldReadLevel::Public,
        ))->indexed();
    }
}
