<?php

declare(strict_types=1);

namespace App\Cms;

/**
 * One-time bootstrap metadata for managed RHT Circle articles.
 *
 * The long-form HTML is read from resources/content/article-seeds/*.html.twig.
 * Once a slug exists in the CMS, the seeder never updates it.
 */
final class ArticleSeedData
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(string $projectRoot): array
    {
        return [
            self::withBlocks($projectRoot, [
                'slug' => 'sagamok-membership-before-trespass',
                'title' => 'Membership should come before trespass',
                'community_slug' => 'sagamok',
                'kicker' => 'Analysis | Sagamok',
                'deck' => 'Sagamok is moving ahead with an enforcement by-law before members have seen the new membership law that will decide who counts as a member.',
                'summary' => 'One law decides who belongs. The other decides who may be removed. Members have been shown the second law first.',
                'author' => 'Russell Jones',
                'date_display' => 'July 26, 2026',
                'date_iso' => '2026-07-26',
                'section' => 'RHT Circle analysis',
                'action_label' => 'Read the analysis',
                'og_description' => 'One law decides who belongs. The other decides who may be removed. Members have been shown the second law first.',
                'social_image' => 'https://rhtcircle.ca/images/news/membership-before-trespass/laws-out-of-order-warning.png',
                'social_image_alt' => 'The laws are out of order. Trespass draft first. Membership rules still unseen.',
                'social_image_width' => 1672,
                'social_image_height' => 941,
                'hero_src' => '/images/news/membership-before-trespass/laws-out-of-order-warning.png',
                'hero_width' => 1672,
                'hero_height' => 941,
                'hero_alt' => 'Warning graphic showing the Trespass By-law draft dated July 21 before a faded Membership Law marked not released.',
                'hero_caption' => 'The Trespass draft is already written. The replacement Membership Law draft has not been released.',
                'promote' => true,
                'sticky' => true,
            ]),
            self::withBlocks($projectRoot, [
                'slug' => 'inside-sagamoks-gr-truss-deal',
                'title' => "Inside Sagamok's GR Truss deal",
                'community_slug' => 'sagamok',
                'kicker' => 'Accountability | Sagamok',
                'deck' => 'A $100 purchase, $900,000 in Council approvals and a documented link to the company behind the $7 million South Market sale.',
                'summary' => 'A $100 purchase, $900,000 in Council approvals and one unanswered repayment question.',
                'author' => 'Laura Owl',
                'date_display' => 'July 25, 2026',
                'date_iso' => '2026-07-25',
                'section' => 'RHT Circle investigation',
                'action_label' => 'Read the investigation',
                'og_description' => 'A $100 purchase, $900,000 in Council approvals and the unanswered $500,000 repayment question.',
                'social_image' => 'https://rhtcircle.ca/images/news/gr-truss/gr-truss-story-2-of-3.png',
                'social_image_alt' => "The passed repayment deadline for Sagamok's $500,000 GR Truss advance.",
                'social_image_width' => 1080,
                'social_image_height' => 1350,
                'hero_src' => '/images/news/gr-truss/gr-truss-story-2-of-3.png',
                'hero_width' => 1080,
                'hero_height' => 1350,
                'hero_alt' => "Graphic showing the passed March 2026 repayment deadline for Sagamok's 500,000 dollar GR Truss advance.",
                'hero_caption' => 'Motion 2025/04 #05 approved $500,000 at 4 per cent, repayable by March 2026. The available minutes do not say whether it was repaid.',
                'promote' => false,
                'sticky' => false,
            ]),
            self::withBlocks($projectRoot, [
                'slug' => 'waasmoowin-deal-public-record',
                'title' => 'The Waasmoowin deal, in the public record',
                'community_slug' => 'sagamok',
                'kicker' => 'Analysis | Sagamok',
                'deck' => 'Eight First Nations can collectively invest in up to half of two Hydro One transmission lines. The opportunity is public. Sagamok\'s ownership, borrowing, cost-sharing and procurement terms are not.',
                'summary' => 'The opportunity is public. Sagamok\'s ownership, borrowing, cost-sharing and procurement terms are not.',
                'author' => 'Russell Jones',
                'date_display' => 'July 26, 2026',
                'date_iso' => '2026-07-26',
                'section' => 'RHT Circle analysis',
                'action_label' => 'Read the analysis',
                'og_description' => 'The Waasmoowin opportunity is public. Sagamok\'s ownership, borrowing, cost-sharing and procurement terms are not.',
                'social_image' => 'https://rhtcircle.ca/images/news/waasmoowin/waasmoowin-public-record.png',
                'social_image_alt' => 'Diagram showing eight First Nations working through Waasmoowin on the North Shore Link and Northeast Power Line, with an opportunity for up to 50 per cent ownership.',
                'social_image_width' => 1200,
                'social_image_height' => 630,
                'hero_src' => '/images/news/waasmoowin/waasmoowin-public-record.png',
                'hero_width' => 1200,
                'hero_height' => 630,
                'hero_alt' => 'Diagram showing eight First Nations working through Waasmoowin on the North Shore Link and Northeast Power Line, with an opportunity for up to 50 per cent ownership.',
                'hero_caption' => 'The public structure and the OEB development-budget record. The $67.2 million increase is a Hydro One figure, not Sagamok\'s stated exposure.',
                'promote' => true,
                'sticky' => false,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private static function withBlocks(string $projectRoot, array $metadata): array
    {
        $slug = (string) $metadata['slug'];
        $path = $projectRoot . '/resources/content/article-seeds/' . $slug . '.html.twig';
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new \RuntimeException('Article seed is missing: ' . $path);
        }

        foreach ([
            'article_body' => 'body_html',
            'article_sidebar' => 'sidebar_html',
            'article_sources' => 'sources_html',
        ] as $block => $field) {
            $pattern = '/{% block ' . preg_quote($block, '/') . ' %}(.*?){% endblock %}/s';
            if (preg_match($pattern, $source, $match) !== 1) {
                throw new \RuntimeException(sprintf('Article seed %s is missing block %s.', $slug, $block));
            }
            $metadata[$field] = trim($match[1]);
        }

        return $metadata;
    }
}
