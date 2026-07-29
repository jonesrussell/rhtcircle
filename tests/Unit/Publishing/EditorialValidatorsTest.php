<?php

declare(strict_types=1);

namespace App\Tests\Unit\Publishing;

use App\Publishing\ArticleContentType;
use App\Publishing\Validator\ImageAltTextValidator;
use App\Publishing\Validator\IsoDateValidator;
use App\Publishing\Validator\NoEmDashValidator;
use App\Publishing\Validator\SlugShapeValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Publishing\ValidationErrors;

/** The public editorial rules, enforced field-specifically at the publishing boundary. */
final class EditorialValidatorsTest extends TestCase
{
    private function errorsFor(object $validator, array $values): array
    {
        $errors = new ValidationErrors();
        $validator->validate($values, $errors);

        return array_column($errors->toArray(), 'message', 'field');
    }

    #[Test]
    public function em_dashes_are_rejected_in_any_field(): void
    {
        $errors = $this->errorsFor(new NoEmDashValidator(), [
            'title' => "Fine title",
            'body_html' => "<p>Bad \u{2014} dash</p>",
        ]);
        self::assertArrayHasKey('body_html', $errors);
        self::assertArrayNotHasKey('title', $errors);
    }

    #[Test]
    public function images_require_alt_text_everywhere(): void
    {
        $errors = $this->errorsFor(new ImageAltTextValidator(), [
            'social_image' => '/media/uploads/x.png',
            'social_image_alt' => '',
            'hero_src' => '/media/uploads/y.png',
            'hero_alt' => 'A described hero',
            'body_html' => '<p><img src="/z.png"></p>',
        ]);
        self::assertArrayHasKey('social_image_alt', $errors);
        self::assertArrayNotHasKey('hero_alt', $errors);
        self::assertArrayHasKey('body_html', $errors);
    }

    #[Test]
    public function slugs_must_be_kebab_case_and_dates_iso(): void
    {
        self::assertArrayHasKey('slug', $this->errorsFor(new SlugShapeValidator(), ['slug' => 'Bad Slug!']));
        self::assertSame([], $this->errorsFor(new SlugShapeValidator(), ['slug' => 'good-slug-9']));
        self::assertArrayHasKey('date_iso', $this->errorsFor(new IsoDateValidator(), ['date_iso' => '2026-13-45']));
        self::assertSame([], $this->errorsFor(new IsoDateValidator(), ['date_iso' => '2026-07-29']));
    }

    #[Test]
    public function the_descriptor_targets_the_existing_article_schema_with_no_new_fields(): void
    {
        $descriptor = ArticleContentType::descriptor();
        self::assertSame('node', $descriptor->entityTypeId);
        self::assertSame('article', $descriptor->bundle);
        // Every writable field is either a node base field or an
        // ArticleFields definition — content ops can never widen the
        // field-access preflight inventory.
        $bundleFields = array_map(static fn($d) => $d->getName(), \App\Cms\ArticleFields::definitions());
        $baseFields = ['slug', 'title', 'promote', 'sticky'];
        foreach (array_keys($descriptor->writableFields) as $field) {
            self::assertTrue(
                \in_array($field, $baseFields, true) || \in_array($field, $bundleFields, true),
                "Writable field '$field' is not part of the existing schema.",
            );
        }
        self::assertArrayNotHasKey('revision_log', $descriptor->writableFields);
        self::assertArrayNotHasKey('status', $descriptor->writableFields);
    }
}
