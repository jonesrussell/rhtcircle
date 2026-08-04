<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Cms\ArticleSeedData;
use App\Content\CommunityHub;
use App\Content\Nations;
use App\Content\NewsFeed;
use App\Content\SagamokAccountabilityHub;
use PHPUnit\Framework\TestCase;

final class NewsFeedTest extends TestCase
{
    public function testEveryStoryDeclaresItsCommunityScope(): void
    {
        foreach (NewsFeed::recentExternalStories() as $story) {
            self::assertArrayHasKey('nations', $story);
            self::assertIsArray($story['nations']);
        }
    }

    public function testNationFeedPrioritizesLocalBeforeTreatyWide(): void
    {
        $stories = NewsFeed::forNation('whitefish-river');

        self::assertSame(['sagamok', 'whitefish-river'], $stories[0]['nations']);
        self::assertSame([], $stories[1]['nations']);
    }

    public function testAllTwentyOneCommunityPagesReceiveCurrentUpdates(): void
    {
        foreach (Nations::all() as $nation) {
            $context = CommunityHub::context(
                (string) $nation['slug'],
                $nation,
                ['total' => 0, 'online' => 0, 'paper' => 0],
            );

            self::assertNotEmpty(
                $context['current_updates'],
                (string) $nation['name'] . ' should have a current public update stream',
            );
        }
    }

    public function testSagamokCommunityPageUsesOneAccountabilityDoorway(): void
    {
        $nation = Nations::find('sagamok');
        self::assertNotNull($nation);

        $context = CommunityHub::context(
            'sagamok',
            $nation,
            ['total' => 40, 'online' => 11, 'paper' => 29],
        );

        self::assertSame('Member accountability', $context['transparency_title']);
        self::assertCount(1, $context['transparency']);
        self::assertSame('/communities/sagamok/accountability', $context['transparency'][0]['href']);
    }

    public function testDedicatedSagamokAccountabilityHubKeepsEveryWorkedResource(): void
    {
        $articles = array_map(
            static fn (array $article): array => $article + [
                'href' => '/news/' . $article['slug'],
                'action' => $article['action_label'],
            ],
            ArticleSeedData::all(\dirname(__DIR__, 3)),
        );
        $groups = SagamokAccountabilityHub::groups(
            ['total' => 40, 'online' => 11, 'paper' => 29],
            $articles,
        );

        self::assertSame(['start-here', 'follow-the-record', 'member-tools'], array_column($groups, 'id'));
        // Data-driven: every published Sagamok article yields exactly one
        // tile (curated slot or the automatic follow-the-record placement),
        // on top of the non-article curated tiles — publishing an article
        // never requires editing this expectation.
        $articleHrefs = array_column($articles, 'href');
        $allCards = array_merge(...array_map(static fn (array $group): array => $group['cards'], $groups));
        $cardHrefs = array_column($allCards, 'href');
        foreach ($articleHrefs as $href) {
            self::assertContains($href, $cardHrefs, $href . ' must surface as a tile automatically.');
        }
        self::assertSame(\count($cardHrefs), \count(array_unique($cardHrefs)), 'No article may be tiled twice.');
        self::assertContains(
            '/communities/sagamok/member-election-law',
            array_column($groups[0]['cards'], 'href'),
        );
        $followTheRecord = $groups[1]['cards'];
        self::assertContains(
            '/news/sagamok-trespass-bylaw-session-was-backwards',
            array_column($followTheRecord, 'href'),
        );
        self::assertContains(
            '/news/sagamok-south-market-land-deal',
            array_column($followTheRecord, 'href'),
        );
    }

    public function testPublicationSeedsTrackPublishedAndRefreshedArticles(): void
    {
        $seeds = ArticleSeedData::all(\dirname(__DIR__, 3));

        self::assertContains('aging-well-starts-before-long-term-care', array_column($seeds, 'slug'));
        self::assertContains('waasmoowin-deal-public-record', array_column($seeds, 'slug'));
        self::assertContains('sagamok-south-market-land-deal', array_column($seeds, 'slug'));
        self::assertContains('sagamok-trespass-bylaw-session-was-backwards', array_column($seeds, 'slug'));
        self::assertNotContains('waasmoowin-deal-public-record', ArticleSeedData::unpublishedSlugs());
        self::assertContains('waasmoowin-deal-public-record', ArticleSeedData::publicationRefreshSlugs());
        self::assertContains('aging-well-starts-before-long-term-care', ArticleSeedData::metadataRefreshSlugs());
    }
}
