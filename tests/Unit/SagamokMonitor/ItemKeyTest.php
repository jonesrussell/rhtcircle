<?php

declare(strict_types=1);

namespace App\Tests\Unit\SagamokMonitor;

use App\Monitor\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec §9.3: duplicate detection is structural.
 *
 * `item_key` is `sha256(normalized_url)` and the side table's primary key does
 * the de-duplication, so these tests are about the normalizer being right —
 * everything downstream inherits it.
 */
final class ItemKeyTest extends TestCase
{
    private const CANONICAL = 'https://www.sagamokanishnawbek.com/notices/water-advisory';

    /** @return iterable<string, array{string}> */
    public static function equivalentVariants(): iterable
    {
        yield 'trailing slash' => [self::CANONICAL . '/'];
        yield 'fragment' => [self::CANONICAL . '#section-2'];
        yield 'host case' => ['https://WWW.SagamokAnishnawbek.com/notices/water-advisory'];
        yield 'utm_source' => [self::CANONICAL . '?utm_source=facebook'];
        yield 'several tracking params' => [self::CANONICAL . '?utm_source=fb&utm_medium=social&fbclid=abc123'];
        yield 'tracking plus fragment plus slash' => [self::CANONICAL . '/?utm_campaign=spring#top'];
        yield 'default https port' => ['https://www.sagamokanishnawbek.com:443/notices/water-advisory'];
    }

    #[DataProvider('equivalentVariants')]
    public function testVariantsCollapseToOneItemKey(string $variant): void
    {
        self::assertSame(
            UrlNormalizer::itemKey(self::CANONICAL),
            UrlNormalizer::itemKey($variant),
            'a tracking/fragment/slash/case variant is the same document',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function distinctUrls(): iterable
    {
        yield 'different path' => ['https://www.sagamokanishnawbek.com/notices/road-closure'];
        yield 'different host' => ['https://example.org/notices/water-advisory'];
        yield 'meaningful query' => [self::CANONICAL . '?page=2'];
        yield 'different scheme' => ['http://www.sagamokanishnawbek.com/notices/water-advisory'];
    }

    #[DataProvider('distinctUrls')]
    public function testGenuinelyDifferentUrlsProduceDifferentKeys(string $other): void
    {
        self::assertNotSame(UrlNormalizer::itemKey(self::CANONICAL), UrlNormalizer::itemKey($other));
    }

    public function testMeaningfulQueryParametersSurviveNormalization(): void
    {
        // Over-aggressive stripping would silently merge distinct documents,
        // which is worse than missing a duplicate: two pages would share one
        // history and one of them would vanish from the dashboard.
        self::assertNotSame(
            UrlNormalizer::itemKey(self::CANONICAL . '?page=2'),
            UrlNormalizer::itemKey(self::CANONICAL . '?page=3'),
        );
    }

    public function testQueryParameterOrderDoesNotMatter(): void
    {
        self::assertSame(
            UrlNormalizer::itemKey(self::CANONICAL . '?a=1&b=2'),
            UrlNormalizer::itemKey(self::CANONICAL . '?b=2&a=1'),
        );
    }

    public function testRootPathIsNotCollapsedAway(): void
    {
        self::assertSame(
            'https://www.sagamokanishnawbek.com/',
            UrlNormalizer::normalize('https://www.sagamokanishnawbek.com/'),
        );
    }

    public function testSameOriginGate(): void
    {
        $origin = 'https://www.sagamokanishnawbek.com/';

        self::assertTrue(UrlNormalizer::isSameOrigin(self::CANONICAL, $origin));
        self::assertFalse(UrlNormalizer::isSameOrigin('https://example.org/x', $origin));
        // A subdomain is a different origin. The members portal would live on
        // one, so this must not be loosened to a suffix match.
        self::assertFalse(UrlNormalizer::isSameOrigin('https://members.sagamokanishnawbek.com/x', $origin));
        self::assertFalse(UrlNormalizer::isSameOrigin('http://www.sagamokanishnawbek.com/x', $origin));
    }
}
