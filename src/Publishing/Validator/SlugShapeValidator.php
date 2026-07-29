<?php

declare(strict_types=1);

namespace App\Publishing\Validator;

use Waaseyaa\Publishing\ContentValidatorInterface;
use Waaseyaa\Publishing\ValidationErrors;

/** Slugs are lowercase kebab-case: they become the public /news/<slug> URL. */
final class SlugShapeValidator implements ContentValidatorInterface
{
    public function validate(array $values, ValidationErrors $errors): void
    {
        $slug = $values['slug'] ?? null;
        if (\is_string($slug) && $slug !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $errors->add('slug', 'Slug must be lowercase kebab-case (a-z, 0-9, hyphens).');
        }
    }
}
