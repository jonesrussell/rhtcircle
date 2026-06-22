<?php

declare(strict_types=1);

namespace App\Content;

/**
 * "What you have heard, and what the record says": the reusable myth-versus-record
 * entries, built to the fact-check standard (lead with the fact, never amplify the
 * false claim, give a clear answer, show the sources, keep it shareable).
 *
 * Content layer for the cross-cutting component rendered by
 * partials/myth_versus_record.html.twig. The /myth-versus-record page shows all
 * entries; the settlement and information-safety pages select the relevant ones by
 * key, so there is one source of truth for each entry.
 *
 * Sourced 2026-06-22. Framed as questions, no private individuals named. Figures are
 * point-in-time.
 */
final class MythEntries
{
    /**
     * Return entries by key, in the order given. An unknown key is skipped.
     *
     * @param list<string> $keys
     *
     * @return list<array<string, mixed>>
     */
    public static function select(array $keys): array
    {
        $all = self::all();
        $out = [];
        foreach ($keys as $key) {
            if (isset($all[$key])) {
                $out[] = $all[$key];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> all entries in display order */
    public static function ordered(): array
    {
        return array_values(self::all());
    }

    /** @return array<string, array<string, mixed>> entries keyed for selection */
    private static function all(): array
    {
        return [
            'legal-fees' => [
                'question' => 'Did the lawyers take most of the settlement?',
                'answer' => 'No. The legal fees were challenged, and a court ordered them reduced substantially.',
                'record' => 'Reporting puts the reduction at around $487 million, against a roughly $10 billion settlement. The fee and disbursement records are something members can ask the litigation fund to share.',
                'takeaway' => 'The fees were cut by the court; the settlement remains for the communities.',
                'sources' => [
                    ['label' => 'CBC, on the settlement, distribution, and legal fees', 'url' => 'https://www.cbc.ca/news/canada/sudbury/money-first-nations-resources-debt-promise-crown-1.7290747'],
                ],
            ],
            'end-of-payments' => [
                'question' => 'Is the settlement the end of the treaty payments?',
                'answer' => 'No. The 2023 settlement is for past compensation.',
                'record' => 'The go-forward annuity, what the yearly payment should be from here on, is still being negotiated between the 21 nations and the Crown and is not yet set.',
                'takeaway' => 'Past compensation is settled; the future annuity is still being decided.',
                'sources' => [
                    ['label' => 'CBC, on the go-forward annuity negotiations', 'url' => 'https://www.cbc.ca/news/canada/sudbury/annual-payments-crown-ontario-canada-sudbury-1.7318824'],
                ],
            ],
            'income-support' => [
                'question' => 'Will a settlement payment affect my Ontario Works or ODSP?',
                'answer' => 'It can, so it is worth checking before you spend or bank it.',
                'record' => 'A large payment can interact with income-support eligibility. Get advice from the Income Security Advocacy Centre or Indigenous legal services. This hub does not give legal or financial advice.',
                'takeaway' => 'Check how a payment affects income support before you act, with someone qualified.',
                'sources' => [
                    ['label' => 'Income Security Advocacy Centre', 'url' => 'https://incomesecurity.org/'],
                ],
            ],
            'hate-group' => [
                'question' => 'Is the white nationalist group in the news targeting our communities?',
                'answer' => "On the public record, the group's named targeting has been of immigrants and newcomers, not First Nations, and there is no documented case of it targeting a specific RHT community.",
                'record' => "At the same time it is active in the territory, its leaders by CBC's account train for what they call a race war, and the broader far-right has a documented history of anti-Indigenous harassment. The honest reading is present and growing, not currently targeting us. See the Community safety section for what to do if approached.",
                'takeaway' => 'Real concern, accurately stated, not alarm.',
                'sources' => [
                    ['label' => 'CBC, a white nationalist group is trying to normalize extremism', 'url' => 'https://www.cbc.ca/news/canada/sudbury/white-nationalist-second-sons-northern-ontario-9.7242451'],
                    ['label' => 'Canadian Anti-Hate Network, White Nationalism in Canada', 'url' => 'https://www.antihate.ca/white_nationalism_in_canada_organized_emboldened_and_growing'],
                ],
            ],
        ];
    }
}
