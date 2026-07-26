<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

use App\Content\NewsFeed;
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
