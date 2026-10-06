<?php

declare(strict_types=1);

namespace App\Publishing\Validator;

use Waaseyaa\Publishing\ContentValidatorInterface;
use Waaseyaa\Publishing\ValidationErrors;

/**
 * Site rule 4: no em dashes (U+2014) anywhere in copy — commas, colons, or
 * new sentences instead. Enforced field-specifically across every string
 * value in the payload (the same rule `bin/lint-copy.php` enforces for
 * hand-authored templates).
 */
final class NoEmDashValidator implements ContentValidatorInterface
{
    public function validate(array $values, ValidationErrors $errors): void
    {
        foreach ($values as $field => $value) {
            if (\is_string($value) && str_contains($value, "\u{2014}")) {
                $errors->add((string) $field, 'Em dashes (U+2014) are not allowed; use commas, colons, or new sentences.');
            }
        }
    }
}
