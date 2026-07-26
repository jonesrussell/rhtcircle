<?php

declare(strict_types=1);

namespace App\Content;

/**
 * A hand-reviewed digest of recent reporting and official updates from across
 * Robinson Huron Treaty territory. RHT Circle publishes its own summaries and
 * sends readers to the original publisher for the full story.
 */
final class NewsFeed
{
    /**
     * @return list<array{
     *   date: string,
     *   date_iso: string,
     *   topic: string,
     *   source: string,
     *   title: string,
     *   summary: string,
     *   url: string,
     *   nations: list<string>
     * }>
     */
    public static function recentExternalStories(): array
    {
        return [
            [
                'date' => 'July 23, 2026',
                'date_iso' => '2026-07-23',
                'topic' => 'Water and infrastructure',
                'source' => 'Indigenous Services Canada',
                'title' => 'Shawanaga opens new water treatment plant',
                'summary' => 'Shawanaga First Nation celebrated its new water treatment plant. The centralized system is designed to serve 101 homes and 10 community buildings, with approximately $31 million in public funding.',
                'url' => 'https://www.newswire.ca/news-releases/shawanaga-first-nation-marks-grand-opening-of-new-water-treatment-plant-874464381.html',
                'nations' => ['shawanaga'],
            ],
            [
                'date' => 'June 30, 2026',
                'date_iso' => '2026-06-30',
                'topic' => 'Community health',
                'source' => 'Anishinabek News',
                'title' => 'First-of-its-kind partnership to transform long-term care in Northern Ontario',
                'summary' => 'Sagamok, Whitefish River, Espanola and the regional hospital announced a partnership to explore a culturally safe long-term care home serving the region.',
                'url' => 'https://anishinabeknews.ca/2026/06/first-of-its-kind-partnership-to-transform-long-term-care-in-northern-ontario/',
                'nations' => ['sagamok', 'whitefish-river'],
            ],
            [
                'date' => 'June 9, 2026',
                'date_iso' => '2026-06-09',
                'topic' => 'Elders and health',
                'source' => 'Wiikwemkoong Unceded Territory',
                'title' => 'Wiikwemkoong documents the new Elders Home project',
                'summary' => 'The Nation’s infrastructure series includes a project update on the planned 96-bed Elders Home, intended to let Elders receive care while staying in the community.',
                'url' => 'https://www.wiikwemkoong.ca/tag/updates/',
                'nations' => ['wiikwemkoong'],
            ],
            [
                'date' => 'May 6, 2026',
                'date_iso' => '2026-05-06',
                'topic' => 'Treaty rights',
                'source' => 'Anishinabek News',
                'title' => 'RHT Nations say Bill C-21 should not be used to advance similar claims in Ontario',
                'summary' => 'Robinson Huron Waawiindamaagewin published the Treaty leadership position on proposed federal Métis self-government legislation and First Nations rights in Ontario.',
                'url' => 'https://anishinabeknews.ca/2026/05/robinson-huron-treaty-nations-issue-public-notice-bill-c-21-should-not-be-used-to-advance-similar-claims-in-ontario/',
                'nations' => [],
            ],
            [
                'date' => 'April 15, 2026',
                'date_iso' => '2026-04-15',
                'topic' => 'Settlement decisions',
                'source' => 'Manitoulin Expositor',
                'title' => 'Wiikwemkoong protestors challenge Council RHT disbursement decisions',
                'summary' => 'The Expositor reported on a member protest and a Federal Court application concerning Wiikwemkoong Council decisions about the community share of the settlement.',
                'url' => 'https://www.manitoulin.com/wiikwemkoong-protestors-challenge-chief-and-councils-rht-disbursement-decisions/',
                'nations' => ['wiikwemkoong'],
            ],
            [
                'date' => 'March 24, 2026',
                'date_iso' => '2026-03-24',
                'topic' => 'Land and water',
                'source' => 'Anishinabek News',
                'title' => 'RHT Nations give no consent for herbicide spraying in Treaty territory',
                'summary' => 'Treaty leadership restated its opposition to glyphosate spraying and called for direct talks with Ontario about forestry practices in the territory.',
                'url' => 'https://anishinabeknews.ca/2026/03/robinson-huron-treaty-nations-public-notice-no-consent-for-herbicide-spraying-in-treaty-territory/',
                'nations' => [],
            ],
            [
                'date' => 'March 4, 2026',
                'date_iso' => '2026-03-04',
                'topic' => 'Youth',
                'source' => 'Anishinabek News',
                'title' => 'First Robinson Huron Treaty Youth Advisory begins its work',
                'summary' => 'Eight appointed members held the advisory body’s first meeting and began work on a Treaty-wide youth gathering and long-term engagement plan.',
                'url' => 'https://anishinabeknews.ca/2026/03/robinson-huron-waawiindamaagewin-launches-first-ever-robinson-huron-treaty-youth-advisory/',
                'nations' => [],
            ],
            [
                'date' => 'February 20, 2026',
                'date_iso' => '2026-02-20',
                'topic' => 'Settlement money',
                'source' => 'RHT Litigation Fund',
                'title' => 'Litigation Fund explains the return and distribution of legal-fee money',
                'summary' => 'The Fund described two pools of returned legal-fee money, their timing and how they would flow to the 21 First Nations under the compensation agreement.',
                'url' => 'https://www.rht1850.ca/_files/ugd/d8bed7_9eff8397c59d4c4a96bb60f0359f7cf4.pdf?index=true',
                'nations' => [],
            ],
            [
                'date' => 'January 15, 2026',
                'date_iso' => '2026-01-15',
                'topic' => 'Land claim',
                'source' => 'RHT Litigation Fund',
                'title' => 'RHT leadership moves to intervene in the Atikameksheng boundary claim',
                'summary' => 'The Fund said Chiefs and Trustees directed an intervention because the boundary case may affect harvesting rights and future annuity calculations across the Treaty territory.',
                'url' => 'https://www.rht1850.ca/_files/ugd/d8bed7_3d5214abe4514789b63d74430da3b2e7.pdf?index=true',
                'nations' => ['atikameksheng'],
            ],
        ];
    }

    /**
     * Nation-specific reporting, followed by the newest Treaty-wide update.
     *
     * @return list<array<string, mixed>>
     */
    public static function forNation(string $slug, int $limit = 3): array
    {
        $specific = [];
        $treatyWide = [];

        foreach (self::recentExternalStories() as $story) {
            if (in_array($slug, $story['nations'], true)) {
                $specific[] = $story;
            } elseif ($story['nations'] === []) {
                $treatyWide[] = $story;
            }
        }

        return array_slice([...$specific, ...$treatyWide], 0, $limit);
    }
}
