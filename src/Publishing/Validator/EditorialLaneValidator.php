<?php

declare(strict_types=1);

namespace App\Publishing\Validator;

use Waaseyaa\Publishing\ContentValidatorInterface;
use Waaseyaa\Publishing\ValidationErrors;

/** Keeps the article bundle's editorial lanes consistent without new bundles. */
final class EditorialLaneValidator implements ContentValidatorInterface
{
    /**
     * @var array<string, array{actions: list<string>, kickers: list<string>}>
     */
    private const array LANES = [
        'RHT Circle analysis' => [
            'actions' => ['Read the analysis'],
            'kickers' => ['Analysis |'],
        ],
        'RHT Circle investigation' => [
            'actions' => ['Read the investigation'],
            'kickers' => ['Investigation |', 'Accountability |'],
        ],
        'RHT Circle commentary' => [
            'actions' => ['Read the commentary'],
            'kickers' => ['Commentary |'],
        ],
    ];

    public function validate(array $values, ValidationErrors $errors): void
    {
        $section = $values['section'] ?? null;
        if (!\is_string($section) || $section === '') {
            return;
        }

        $lane = self::LANES[$section] ?? null;
        if ($lane === null) {
            $errors->add('section', 'Use a defined RHT Circle editorial lane.');

            return;
        }

        $action = $values['action_label'] ?? null;
        if (\is_string($action) && $action !== '' && !\in_array($action, $lane['actions'], true)) {
            $errors->add('action_label', 'The action label does not match the article editorial lane.');
        }

        $kicker = $values['kicker'] ?? null;
        if (\is_string($kicker) && $kicker !== '') {
            foreach ($lane['kickers'] as $prefix) {
                if (str_starts_with($kicker, $prefix)) {
                    return;
                }
            }
            $errors->add('kicker', 'The kicker does not match the article editorial lane.');
        }
    }
}
