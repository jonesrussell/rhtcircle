<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

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
        $html = $this->renderer->render('pages/news/index.html.twig', [
            'stories' => NewsFeed::recentExternalStories(),
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
            'groups' => SagamokAccountabilityHub::groups(['total' => 40, 'online' => 11, 'paper' => 29]),
        ]);

        self::assertStringContainsString('Follow the record. Find the question. Take the next step.', $html);
        self::assertStringContainsString('id="start-here"', $html);
        self::assertStringContainsString('id="follow-the-record"', $html);
        self::assertStringContainsString('id="member-tools"', $html);
        self::assertSame(18, substr_count($html, '<a class="tile-card'));
        self::assertStringContainsString('Back to the Sagamok community page', $html);
        self::assertMatchesRegularExpression(
            '~<main id="main">\s*<div class="wrap wrap--wide">~',
            $html,
        );
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
        $html = $this->renderer->render('pages/news/inside-sagamoks-gr-truss-deal.html.twig');

        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<header class="site-head">', $html);
        self::assertStringContainsString('<article class="news-article">', $html);
        self::assertStringContainsString('By Laura Owl', $html);
        self::assertMatchesRegularExpression(
            '~<meta property="og:image" content="https://rhtcircle\.ca/images/og/pages-news-inside-sagamoks-gr-truss-deal\.png\?v=[a-f0-9]{8}">~',
            $html,
        );
        self::assertStringNotContainsString('<style>', $html);
        self::assertSame(1, substr_count(strtolower($html), '<!doctype html>'));
    }

    public function testMembershipBeforeTrespassUsesArticleLayoutAndEditorialImage(): void
    {
        $_SERVER['REQUEST_URI'] = '/news/sagamok-membership-before-trespass';
        $html = $this->renderer->render('pages/news/sagamok-membership-before-trespass.html.twig');

        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<article class="news-article">', $html);
        self::assertStringContainsString('Membership should come before trespass', $html);
        self::assertStringContainsString('By Russell Jones', $html);
        self::assertStringContainsString(
            '<meta property="og:image" content="https://rhtcircle.ca/images/news/membership-before-trespass/membership-comes-first.png">',
            $html,
        );
        self::assertStringContainsString('The chronology does not prove', $html);
        self::assertSame(1, substr_count(strtolower($html), '<!doctype html>'));
    }

    public function testEditorCanOverrideTheGeneratedSocialImage(): void
    {
        $html = $this->renderer->render('pages/news/inside-sagamoks-gr-truss-deal.html.twig', [
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
}
