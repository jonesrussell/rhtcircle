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
        self::assertSame(21, array_sum(array_map(
            static fn (array $group): int => count($group['cards']),
            $groups,
        )));
        $followTheRecord = $groups[1]['cards'];
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
        self::assertNotContains('waasmoowin-deal-public-record', ArticleSeedData::unpublishedSlugs());
        self::assertContains('waasmoowin-deal-public-record', ArticleSeedData::publicationRefreshSlugs());
        self::assertContains('aging-well-starts-before-long-term-care', ArticleSeedData::metadataRefreshSlugs());
    }
}
