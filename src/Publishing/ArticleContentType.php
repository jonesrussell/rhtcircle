<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Cms\ArticleFields;
use App\Publishing\Validator\ImageAltTextValidator;
use App\Publishing\Validator\IsoDateValidator;
use App\Publishing\Validator\NoEmDashValidator;
use App\Publishing\Validator\SlugShapeValidator;
use App\Publishing\Validator\EditorialLaneValidator;
use App\Publishing\ArticleHtmlSanitizer;
use Waaseyaa\Publishing\ContentTypeDescriptor;
use Waaseyaa\Publishing\FieldSpec;

/**
 * The RHT Circle article publishing contract: the app-owned side of the
 * framework ContentPublisher. Maps the EXISTING node/article schema
 * ({@see ArticleFields} + node base fields) — zero schema change, so content
 * operations never touch the field-access preflight — and carries the
 * public editorial rules: no em dashes anywhere, alt text required whenever
 * an image is set, slug shape, ISO dates, and an explicit HTML allowlist for
 * body/sidebar/sources derived from the article layout's markup.
 */
final class ArticleContentType
{
    public const string CAPABILITY = 'publish rht articles';

    public static function descriptor(): ContentTypeDescriptor
    {
        return new ContentTypeDescriptor(
            entityTypeId: 'node',
            bundle: ArticleFields::BUNDLE,
            slugField: 'slug',
            statusField: 'status',
            writableFields: [
                // Node base fields.
                'slug' => new FieldSpec(type: 'string', required: true, maxLength: 120),
                'title' => new FieldSpec(type: 'string', required: true, maxLength: 200),
                'promote' => new FieldSpec(type: 'bool'),
                'sticky' => new FieldSpec(type: 'bool'),
                // Article bundle fields (ArticleFields::definitions()).
                'community_slug' => new FieldSpec(type: 'string', required: true, maxLength: 60),
                'kicker' => new FieldSpec(type: 'string', maxLength: 120),
                'deck' => new FieldSpec(type: 'text', maxLength: 500),
                'summary' => new FieldSpec(type: 'text', required: true, maxLength: 500),
                'author' => new FieldSpec(type: 'string', required: true, maxLength: 120),
                'date_display' => new FieldSpec(type: 'string', required: true, maxLength: 60),
                'date_iso' => new FieldSpec(type: 'string', required: true, maxLength: 10),
                'section' => new FieldSpec(type: 'string', required: true, maxLength: 60),
                'action_label' => new FieldSpec(type: 'string', maxLength: 80),
                'og_description' => new FieldSpec(type: 'text', maxLength: 300),
                'social_image' => new FieldSpec(type: 'string', maxLength: 500),
                'social_image_alt' => new FieldSpec(type: 'text', maxLength: 400),
                'social_image_width' => new FieldSpec(type: 'int'),
                'social_image_height' => new FieldSpec(type: 'int'),
                'hero_src' => new FieldSpec(type: 'string', maxLength: 500),
                'hero_width' => new FieldSpec(type: 'int'),
                'hero_height' => new FieldSpec(type: 'int'),
                'hero_alt' => new FieldSpec(type: 'text', maxLength: 400),
                'hero_caption' => new FieldSpec(type: 'text', maxLength: 500),
                'body_html' => new FieldSpec(type: 'text', required: true, html: true),
                'sidebar_html' => new FieldSpec(type: 'text', html: true),
                'sources_html' => new FieldSpec(type: 'text', required: true, html: true),
            ],
            htmlSanitizer: new ArticleHtmlSanitizer(),
            validators: [
                new NoEmDashValidator(),
                new ImageAltTextValidator(),
                new SlugShapeValidator(),
                new IsoDateValidator(),
                new EditorialLaneValidator(),
            ],
            publishCapability: self::CAPABILITY,
        );
    }

}
