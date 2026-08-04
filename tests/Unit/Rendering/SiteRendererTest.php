<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

use App\Cms\ArticleSeedData;
use App\Content\NewsFeed;
use App\Content\Nations;
use App\Content\SagamokAccountabilityHub;
use App\Rendering\PublicAssetVersioner;
use App\Rendering\SiteRenderer;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SSR\ThemeServiceProvider;

final class SiteRendererTest extends TestCase
{
    private SiteRenderer $renderer;

    protected function setUp(): void
    {
        $root = \dirname(__DIR__, 3);
        $this->renderer = new SiteRenderer(
            ThemeServiceProvider::createTwigEnvironment($root),
            $root,
            new PublicAssetVersioner($root . '/public'),
        );
        $_SERVER['REQUEST_URI'] = '/news';
    }

    public function testNewsIndexUsesTheSharedShellAndComponents(): void
    {
        $articles = $this->articles();
        $html = $this->renderer->render('pages/news/index.html.twig', [
            'stories' => NewsFeed::recentExternalStories(),
            'feature_article' => $articles[0],
            'reporting_articles' => array_slice($articles, 1),
            'regions' => Nations::regions(),
            'communities_by_region' => Nations::byRegion(),
            'nation_names' => array_column(Nations::all(), 'name', 'slug'),
        ]);

        self::assertStringContainsString('<header class="site-head">', $html);
        self::assertStringContainsString('<article class="news-feature">', $html);
        self::assertStringContainsString('class="wrap wrap--wide"', $html);
        self::assertStringNotContainsString('<style>', $html);
        self::assertSame(1, substr_count(strtolower($html), '<!doctype html>'));
    }

    public function testHomepageIsAThreeLayerPublicationFrontDoor(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $nationNames = [];
        foreach (Nations::all() as $nation) {
            $nationNames[(string) $nation['slug']] = (string) $nation['name'];
        }

        $html = $this->renderer->render('pages/home.html.twig', [
            'stories' => NewsFeed::recentExternalStories(),
            'regions' => Nations::regions(),
            'communities_by_region' => Nations::byRegion(),
            'nation_names' => $nationNames,
        ]);

        self::assertStringContainsString('News and public records members can use.', $html);
        self::assertSame(3, substr_count($html, '<article class="news-card">'));
        self::assertStringContainsString('One Treaty, 21 community desks', $html);
        self::assertStringContainsString('Find the right doorway.', $html);
        self::assertStringNotContainsString('Today, July 23', $html);
    }

    public function testSharedHeaderUsesFocusedDesktopAndMobileNavigation(): void
    {
        $html = $this->renderer->render('pages/about.html.twig');

        self::assertStringContainsString('class="site-nav site-nav--desktop"', $html);
        self::assertStringContainsString('<details class="mobile-menu">', $html);
        self::assertStringContainsString('<div id="rht-furniture-host"></div>', $html);
        self::assertStringContainsString('>21 Nations</a>', $html);
        self::assertStringContainsString('>Help &amp; resources</a>', $html);
    }

    public function testGetInvolvedUsesTheWidePublicationDoorwayLayout(): void
    {
        $_SERVER['REQUEST_URI'] = '/get-involved';
        $html = $this->renderer->render('pages/get-involved.html.twig');

        self::assertStringContainsString('<body class="route-get-involved">', $html);
        self::assertStringContainsString('<main id="main">', $html);
        self::assertMatchesRegularExpression(
            '~<main id="main">\s*<div class="wrap wrap--wide">~',
            $html,
        );
        self::assertSame(5, substr_count($html, '<a class="tile-card'));
        self::assertStringContainsString('class="contribute-layout"', $html);
        self::assertStringContainsString('class="contribute-support"', $html);
    }

    public function testSagamokAccountabilityDeskIsOrganizedAsItsOwnPage(): void
    {
        $_SERVER['REQUEST_URI'] = '/communities/sagamok/accountability';
        $html = $this->renderer->render('pages/communities/sagamok/accountability.html.twig', [
            'groups' => SagamokAccountabilityHub::groups(
                ['total' => 40, 'online' => 11, 'paper' => 29],
                $this->articles(),
            ),
        ]);

        self::assertStringContainsString('Follow the record. Find the question. Take the next step.', $html);
        self::assertStringContainsString('id="start-here"', $html);
        self::assertStringContainsString('id="follow-the-record"', $html);
        self::assertStringContainsString('id="member-tools"', $html);
        // Data-driven: the template renders exactly one tile per card in the
        // groups model (which itself auto-places every published article).
        $expectedTiles = array_sum(array_map(
            static fn (array $group): int => \count($group['cards']),
            \App\Content\SagamokAccountabilityHub::groups(
                ['total' => 40, 'online' => 11, 'paper' => 29],
                $this->articles(),
            ),
        ));
        self::assertSame($expectedTiles, substr_count($html, '<a class="tile-card'));
        self::assertStringContainsString('/communities/sagamok/member-election-law', $html);
        self::assertStringContainsString('/news/sagamok-trespass-bylaw-session-was-backwards', $html);
        self::assertStringContainsString('/news/sagamok-south-market-land-deal', $html);
        self::assertStringContainsString('Back to the Sagamok community page', $html);
        self::assertMatchesRegularExpression(
            '~<main id="main">\s*<div class="wrap wrap--wide">~',
            $html,
        );
    }

    public function testMemberElectionLawIsClearlyUnofficialAndInvitesFeedback(): void
    {
        $_SERVER['REQUEST_URI'] = '/communities/sagamok/member-election-law';
        $html = $this->renderer->render('pages/communities/sagamok/member-election-law.html.twig');

        self::assertStringContainsString('Not official or enacted', $html);
        self::assertStringContainsString('section 42 member vote and a federal removal order', $html);
        self::assertStringContainsString('/files/sagamok-election-law-member-counterdraft.html', $html);
        self::assertStringContainsString('This is open for member feedback.', $html);
        self::assertStringContainsString('href="/contact"', $html);
    }

    public function testNoPageCanSelectTheNarrowReadingWrapperAsItsMainLayout(): void
    {
        $root = \dirname(__DIR__, 3);
        $base = file_get_contents($root . '/templates/base.html.twig');

        self::assertIsString($base);
        self::assertStringContainsString(
            '{% block main_class %}wrap wrap--wide{% endblock %}',
            $base,
        );

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates/pages'),
        );
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $template = file_get_contents($file->getPathname());
            self::assertIsString($template);
            self::assertStringNotContainsString(
                '{% block main_class %}wrap{% endblock %}',
                $template,
                $file->getPathname() . ' selects the forbidden narrow page shell.',
            );
        }
    }

    public function testInvestigationUsesTheNewsArticleLayout(): void
    {
        $_SERVER['REQUEST_URI'] = '/news/inside-sagamoks-gr-truss-deal';
        $html = $this->renderer->render('pages/news/article.html.twig', [
            'article' => $this->article('inside-sagamoks-gr-truss-deal'),
        ]);

        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<header class="site-head">', $html);
        self::assertStringContainsString('<article class="news-article">', $html);
        self::assertStringContainsString('By Laura Owl', $html);
        self::assertStringContainsString(
            '<meta property="og:image" content="https://rhtcircle.ca/images/news/gr-truss/gr-truss-story-2-of-3.png">',
            $html,
        );
        self::assertStringNotContainsString('<style>', $html);
        self::assertSame(1, substr_count(strtolower($html), '<!doctype html>'));
    }

    public function testMembershipBeforeTrespassUsesArticleLayoutAndEditorialImage(): void
    {
        $_SERVER['REQUEST_URI'] = '/news/sagamok-membership-before-trespass';
        $html = $this->renderer->render('pages/news/article.html.twig', [
            'article' => $this->article('sagamok-membership-before-trespass'),
        ]);

        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<article class="news-article">', $html);
        self::assertStringContainsString('Membership should come before trespass', $html);
        self::assertStringContainsString('By Russell Jones', $html);
        self::assertStringContainsString(
            '<meta property="og:image" content="https://rhtcircle.ca/images/news/membership-before-trespass/laws-out-of-order-warning.png">',
            $html,
        );
        self::assertStringContainsString('The chronology does not prove', $html);
        self::assertSame(1, substr_count(strtolower($html), '<!doctype html>'));
    }

    public function testTrespassSessionAnalysisUsesArticleLayoutAndDedicatedSocialCard(): void
    {
        $_SERVER['REQUEST_URI'] = '/news/sagamok-trespass-bylaw-session-was-backwards';
        $html = $this->renderer->render('pages/news/article.html.twig', [
            'article' => $this->article('sagamok-trespass-bylaw-session-was-backwards'),
        ]);

        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<article class="news-article">', $html);
        self::assertStringContainsString('The Trespass By-law session was backwards', $html);
        self::assertStringContainsString('By Russell Jones', $html);
        self::assertStringContainsString(
            '<meta property="og:image" content="https://rhtcircle.ca/images/news/trespass-session/trespass-session-was-backwards.png">',
            $html,
        );
        self::assertStringContainsString('The session had the people who wrote the by-law.', $html);
        self::assertSame(1, substr_count(strtolower($html), '<!doctype html>'));
    }

    public function testEditorCanOverrideTheGeneratedSocialImage(): void
    {
        $html = $this->renderer->render('pages/news/article.html.twig', [
            'article' => $this->article('inside-sagamoks-gr-truss-deal'),
            'og_image_override' => 'https://rhtcircle.ca/images/editorial/custom-social-card.png',
        ]);

        self::assertStringContainsString(
            '<meta property="og:image" content="https://rhtcircle.ca/images/editorial/custom-social-card.png">',
            $html,
        );
        self::assertStringContainsString(
            '<meta name="twitter:image" content="https://rhtcircle.ca/images/editorial/custom-social-card.png">',
            $html,
        );
    }

    public function testPageEditingDataCanOverrideTheGeneratedSocialImage(): void
    {
        $html = $this->renderer->render('pages/news/index.html.twig', [
            'stories' => NewsFeed::recentExternalStories(),
            'page' => [
                'social_image' => 'https://rhtcircle.ca/images/editorial/news-desk.png',
                'social_image_alt' => 'The RHT Circle news desk.',
                'social_image_width' => 1600,
                'social_image_height' => 900,
            ],
        ]);

        self::assertStringContainsString(
            '<meta property="og:image" content="https://rhtcircle.ca/images/editorial/news-desk.png">',
            $html,
        );
        self::assertStringContainsString('<meta property="og:image:width" content="1600">', $html);
        self::assertStringContainsString('<meta property="og:image:height" content="900">', $html);
        self::assertStringContainsString('<meta property="og:image:alt" content="The RHT Circle news desk.">', $html);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articles(): array
    {
        $articles = [];
        foreach (ArticleSeedData::all(\dirname(__DIR__, 3)) as $seed) {
            $articles[] = $this->articleView($seed);
        }

        return $articles;
    }

    /**
     * @return array<string, mixed>
     */
    private function article(string $slug): array
    {
        foreach (ArticleSeedData::all(\dirname(__DIR__, 3)) as $seed) {
            if ($seed['slug'] === $slug) {
                return $this->articleView($seed);
            }
        }

        self::fail('Missing article fixture: ' . $slug);
    }

    /**
     * @param array<string, mixed> $seed
     * @return array<string, mixed>
     */
    private function articleView(array $seed): array
    {
        return $seed + [
            'href' => '/news/' . $seed['slug'],
            'url' => '/news/' . $seed['slug'],
            'internal' => true,
            'date' => $seed['date_display'],
            'action' => $seed['action_label'],
            'hero' => [
                'src' => $seed['hero_src'],
                'width' => $seed['hero_width'],
                'height' => $seed['hero_height'],
                'alt' => $seed['hero_alt'],
                'caption' => $seed['hero_caption'],
            ],
        ];
    }
}
