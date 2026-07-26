<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\CommunityHub;
use App\Content\Nations;
use App\Content\NewsFeed;
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
}
