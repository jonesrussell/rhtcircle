<?php

declare(strict_types=1);

namespace App\Publishing\Validator;

use Waaseyaa\Publishing\ContentValidatorInterface;
use Waaseyaa\Publishing\ValidationErrors;

/**
 * Alt text is required for every image the article sets: the social image,
 * the hero image, and every <img> inside HTML fields must carry a non-empty
 * alt attribute.
 */
final class ImageAltTextValidator implements ContentValidatorInterface
{
    public function validate(array $values, ValidationErrors $errors): void
    {
        foreach ([['social_image', 'social_image_alt'], ['hero_src', 'hero_alt']] as [$imageField, $altField]) {
            $image = $values[$imageField] ?? null;
            $alt = $values[$altField] ?? null;
            if (\is_string($image) && trim($image) !== '' && (!\is_string($alt) || trim($alt) === '')) {
                $errors->add($altField, sprintf('Alt text is required when %s is set.', $imageField));
            }
        }

        foreach (['body_html', 'sidebar_html', 'sources_html'] as $htmlField) {
            $html = $values[$htmlField] ?? null;
            if (!\is_string($html) || !str_contains($html, '<img')) {
                continue;
            }
            if (preg_match('/<img(?![^>]*\balt\s*=\s*"[^"]+")[^>]*>/i', $html) === 1) {
                $errors->add($htmlField, 'Every inline image needs a non-empty alt attribute.');
            }
        }
    }
}
