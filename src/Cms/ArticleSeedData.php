<?php

declare(strict_types=1);

namespace App\Cms;

/**
 * One-time bootstrap metadata for managed RHT Circle articles.
 *
 * The long-form HTML is read from resources/content/article-seeds/*.html.twig.
 * Once a slug exists in the CMS, the seeder leaves it untouched unless the
 * slug is explicitly named for a controlled publication or metadata refresh.
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
                'slug' => 'sagamok-membership-did-not-begin-with-the-list',
                'title' => 'Membership did not begin with the list',
                'community_slug' => 'sagamok',
                'kicker' => 'Analysis | Sagamok',
                'deck' => 'Alan Ojiig Corbiere spoke about how Anishinaabe people knew who they were, who they were related to and where they belonged before Canada made the list.',
                'summary' => 'Alan Ojiig Corbiere began with relationships, names, doodem, language and the land, not a government list.',
                'author' => 'Russell Jones',
                'date_display' => 'August 11, 2026',
                'date_iso' => '2026-08-11',
                'section' => 'RHT Circle analysis',
                'action_label' => 'Read the analysis',
                'og_description' => 'Alan Ojiig Corbiere\'s Membership Law presentation began with relationships, names, doodem, language and territory, not a government list.',
                'social_image' => 'https://rhtcircle.ca/images/news/membership-before-the-list/alan-relationships-and-record.jpg',
                'social_image_alt' => 'Alan Ojiig Corbiere presenting a historical record of Anishinaabe names and doodem marks at the Sagamok Membership Law gathering.',
                'social_image_width' => 1280,
                'social_image_height' => 560,
                'hero_src' => '/images/news/membership-before-the-list/alan-relationships-and-record.jpg',
                'hero_width' => 1280,
                'hero_height' => 560,
                'hero_alt' => 'Alan Ojiig Corbiere presenting a historical record of Anishinaabe names and doodem marks at the Sagamok Membership Law gathering.',
                'hero_caption' => 'Alan Ojiig Corbiere spoke about names, doodem, language and the ways Anishinaabe people knew where they belonged.',
                'promote' => false,
                'sticky' => false,
            ]),
            self::withBlocks($projectRoot, [
                'slug' => 'sagamok-trespass-bylaw-session-was-backwards',
                'title' => 'The Trespass By-law session was backwards',
                'community_slug' => 'sagamok',
                'kicker' => 'Analysis | Sagamok',
                'deck' => 'The July 28 recording shows how the draft began, what powers it creates, what was left for later and which questions only leadership could answer.',
                'summary' => 'The lawyers could explain the draft. They could not answer what Council intends to do with it.',
                'author' => 'Russell Jones',
                'date_display' => 'July 28, 2026',
                'date_iso' => '2026-07-28',
                'section' => 'RHT Circle analysis',
                'action_label' => 'Read the analysis',
                'og_description' => 'The lawyers could explain the draft. They could not answer what Council intends to do with it.',
                'social_image' => 'https://rhtcircle.ca/images/news/trespass-session/trespass-session-was-backwards.png',
                'social_image_alt' => 'The Trespass By-law session was backwards. Lawyers explained the draft, but Council was not there to answer for it.',
                'social_image_width' => 1200,
                'social_image_height' => 630,
                'hero_src' => '/images/news/trespass-session/trespass-session-was-backwards.png',
                'hero_width' => 1200,
                'hero_height' => 630,
                'hero_alt' => 'The Trespass By-law session was backwards. Lawyers explained the draft, but Council was not there to answer for it.',
                'hero_caption' => 'The session had the people who wrote the by-law. It did not have the people who must answer for it.',
                'promote' => true,
                'sticky' => true,
            ]),
            self::withBlocks($projectRoot, [
                'slug' => 'sagamok-south-market-land-deal',
                'title' => 'Bought for $1.2 million. Sold to Sagamok for $7 million.',
                'community_slug' => 'sagamok',
                'kicker' => 'Investigation | Sagamok',
                'deck' => 'Public records connect seller Paul Guindon\'s companies to Serpent River housing projects and a GR Truss financing record involving Sagamok adviser Ryan McLeod.',
                'summary' => 'A Sault property bought for $1.2 million was sold into Sagamok\'s housing project fifteen months later for $7 million.',
                'author' => 'Russell Jones',
                'date_display' => 'July 27, 2026',
                'date_iso' => '2026-07-27',
                'section' => 'RHT Circle investigation',
                'action_label' => 'Read the investigation',
                'og_description' => 'The South Market land transaction and the public records connecting Paul Guindon\'s companies, Serpent River housing, Ryan McLeod and GR Truss.',
                'social_image' => 'https://rhtcircle.ca/images/news/south-market/south-market-land-deal.png',
                'social_image_alt' => 'South Market land transaction showing a $1.2 million purchase followed fifteen months later by a $7 million sale to Sagamok.',
                'social_image_width' => 1200,
                'social_image_height' => 630,
                'hero_src' => '/images/news/south-market/south-market-land-deal.png',
                'hero_width' => 1200,
                'hero_height' => 630,
                'hero_alt' => 'South Market land transaction showing a 1.2 million dollar purchase followed fifteen months later by a 7 million dollar sale to Sagamok.',
                'hero_caption' => 'Ontario land records show the South Market property was bought for $1.2 million and sold into Sagamok\'s project fifteen months later for $7 million.',
                'promote' => true,
                'sticky' => true,
                'created' => 1785189600,
                'changed' => 1785189600,
            ]),
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
                'slug' => 'waasmoowin-deal-public-record',
                'title' => "Who holds Sagamok's Waasmoowin roles?",
                'community_slug' => 'sagamok',
                'kicker' => 'Analysis | Sagamok',
                'deck' => 'Waasmoowin brings eight First Nations together around two Hydro One transmission projects. Four Sagamok role lanes are visible. The Nation\'s terms are not.',
                'summary' => 'What Waasmoowin is, who carries Sagamok\'s work and which ownership, borrowing and procurement terms remain unseen.',
                'author' => 'Russell Jones',
                'date_display' => 'July 27, 2026',
                'date_iso' => '2026-07-27',
                'section' => 'RHT Circle analysis',
                'action_label' => 'Read the analysis',
                'og_description' => 'What Waasmoowin is, who carries Sagamok\'s work and which ownership, borrowing and procurement terms remain unseen.',
                'social_image' => 'https://rhtcircle.ca/images/news/waasmoowin/waasmoowin-public-record.png',
                'social_image_alt' => 'Role map identifying Angus Toulouse, Michelle Toulouse, Evan O\'Leary and Ryan McLeod in Sagamok\'s Waasmoowin work.',
                'social_image_width' => 1200,
                'social_image_height' => 630,
                'hero_src' => '/images/news/waasmoowin/waasmoowin-public-record.png',
                'hero_width' => 1200,
                'hero_height' => 630,
                'hero_alt' => 'Role map identifying Angus Toulouse, Michelle Toulouse, Evan O\'Leary and Ryan McLeod in Sagamok\'s Waasmoowin work.',
                'hero_caption' => 'The public record identifies four Sagamok role lanes. The five SDC directors provide separate corporate oversight.',
                'promote' => false,
                'sticky' => false,
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
                'slug' => 'aging-well-starts-before-long-term-care',
                'title' => 'Aging well starts before long-term care',
                'community_slug' => 'circle',
                'kicker' => 'Commentary | Elders and health',
                'deck' => 'A new regional long-term-care proposal has reopened a larger question: are our communities investing enough in what helps Elders remain connected to family, land, language and community before institutional care becomes necessary?',
                'summary' => 'Long-term care may be necessary for some people. Aging well begins much earlier, in the homes, relationships and communities that help people remain well and connected.',
                'author' => 'Windigo Kaan',
                'date_display' => 'July 26, 2026',
                'date_iso' => '2026-07-26',
                'section' => 'RHT Circle commentary',
                'action_label' => 'Read the commentary',
                'og_description' => 'A contributor commentary on the supports, relationships and community choices that help Elders remain well and connected before institutional care becomes necessary.',
                'social_image' => 'https://rhtcircle.ca/images/news/aging-well/aging-well-starts-before-long-term-care.png',
                'social_image_alt' => 'Aging well starts before long-term care. Commentary by Windigo Kaan.',
                'social_image_width' => 1200,
                'social_image_height' => 630,
                'hero_src' => '',
                'hero_width' => 0,
                'hero_height' => 0,
                'hero_alt' => '',
                'hero_caption' => '',
                'promote' => true,
                'sticky' => false,
            ]),
        ];
    }

    /**
     * Existing CMS records that must remain stored but unavailable publicly.
     *
     * @return list<string>
     */
    public static function unpublishedSlugs(): array
    {
        return [];
    }

    /**
     * Existing records intentionally republished from their reviewed source.
     *
     * @return list<string>
     */
    public static function publicationRefreshSlugs(): array
    {
        return [
            'waasmoowin-deal-public-record',
            'sagamok-trespass-bylaw-session-was-backwards',
        ];
    }

    /**
     * Seeded records whose selected publication metadata is intentionally
     * refreshed after initial migration.
     *
     * @return list<string>
     */
    public static function metadataRefreshSlugs(): array
    {
        return ['aging-well-starts-before-long-term-care'];
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
