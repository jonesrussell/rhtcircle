<?php

declare(strict_types=1);

namespace App\Publishing\Validator;

use Waaseyaa\Publishing\ContentValidatorInterface;
use Waaseyaa\Publishing\ValidationErrors;

/** `date_iso` drives sitemap lastmod + article metadata: strict Y-m-d. */
final class IsoDateValidator implements ContentValidatorInterface
{
    public function validate(array $values, ValidationErrors $errors): void
    {
        $date = $values['date_iso'] ?? null;
        if (!\is_string($date) || $date === '') {
            return; // required-ness is the schema's job
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $errors->add('date_iso', 'Must be a valid ISO date (YYYY-MM-DD).');
        }
    }
}
