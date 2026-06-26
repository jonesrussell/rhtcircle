<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lexicon;

use App\Lexicon\LexiconClient;
use App\Tests\Support\Lexicon\ArrayLexiconCache;
use App\Tests\Support\Lexicon\FakeHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\HttpResponse;

/**
 * The LexiconClient contract: parse a real Minoo response, handle a clean miss,
 * fail soft when Minoo is unreachable or errors, and cache only real answers.
 */
final class LexiconClientTest extends TestCase
{
    /** The exact "spoon" payload Minoo returns (trimmed to the fields we use). */
    private const string SPOON_JSON = '{"match_type":"exact","query":"spoon","tag":"oj-x-sagamok","dir":null,"count":1,'
        . '"matches":[{"word":"Emkwaan","definition":["spoon"],"tag":"oj-x-sagamok","dialect":"Nishnaabemwin",'
        . '"label":"Nishnaabemwin (Sagamok)","slug":"emkwaan","match_type":"exact","match_score":100,"matched_on":"en",'
        . '"provenance":{"attribution_source":"corpus","attribution":"Sagamok community Knowledge Keepers",'
        . '"source_url":"https://www.facebook.com/reel/901425976280455"}}],'
        . '"usage":{"governance":"OCAP","community_governed":true,"noncommercial":true,"license":null,'
        . '"terms":"Community-governed.","reference":{"url":"https://ojibwe.lib.umn.edu"}}}';

    private const string MISS_JSON = '{"match_type":"miss","query":"zzzqqx","tag":"oj-x-sagamok","dir":null,'
        . '"count":0,"matches":[],"usage":{"governance":"OCAP","community_governed":true,"noncommercial":true}}';

    #[Test]
    public function spoon_returns_emkwaan_with_attribution(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, self::SPOON_JSON));
        $client = new LexiconClient($http, new ArrayLexiconCache());

        $result = $client->lookup('spoon');

        self::assertTrue($result->available);
        self::assertSame('exact', $result->matchType);
        self::assertSame(1, $result->count);
        self::assertCount(1, $result->matches);

        $match = $result->matches[0];
        self::assertSame('Emkwaan', $match->word);
        self::assertSame(['spoon'], $match->definitions);
        self::assertSame('Nishnaabemwin (Sagamok)', $match->label);
        self::assertSame('Sagamok community Knowledge Keepers', $match->attribution);
        self::assertSame('https://www.facebook.com/reel/901425976280455', $match->sourceUrl);

        self::assertNotNull($result->usage);
        self::assertSame('OCAP', $result->usage->governance);
        self::assertTrue($result->usage->noncommercial);
    }

    #[Test]
    public function spoon_request_carries_the_query_and_default_tag(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, self::SPOON_JSON));
        new LexiconClient($http, new ArrayLexiconCache())->lookup('spoon');

        self::assertStringContainsString('q=spoon', $http->urls[0]);
        self::assertStringContainsString('tag=oj-x-sagamok', $http->urls[0]);
    }

    #[Test]
    public function a_made_up_word_returns_a_clean_no_result_not_an_error(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, self::MISS_JSON));
        $result = new LexiconClient($http, new ArrayLexiconCache())->lookup('zzzqqx');

        self::assertTrue($result->available);
        self::assertSame('miss', $result->matchType);
        self::assertSame(0, $result->count);
        self::assertFalse($result->hasMatches());
    }

    #[Test]
    public function minoo_unreachable_fails_soft_without_throwing(): void
    {
        $http = new FakeHttpClient(throw: new HttpRequestException('down', 'url', 'GET'));
        $result = new LexiconClient($http, new ArrayLexiconCache())->lookup('spoon');

        self::assertFalse($result->available);
        self::assertSame(0, $result->count);
        self::assertFalse($result->hasMatches());
        self::assertSame('spoon', $result->query);
    }

    #[Test]
    public function a_non_200_response_fails_soft_and_is_not_cached(): void
    {
        $http = new FakeHttpClient(new HttpResponse(422, '{"error":"bad"}'));
        $cache = new ArrayLexiconCache();
        $client = new LexiconClient($http, $cache);

        self::assertFalse($client->lookup('spoon')->available);
        self::assertSame(0, $cache->writes, 'an unavailable result must not be cached');

        // The next lookup retries Minoo rather than serving a pinned failure.
        $client->lookup('spoon');
        self::assertSame(2, $http->calls);
    }

    #[Test]
    public function an_identical_lookup_is_served_from_cache(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, self::SPOON_JSON));
        $client = new LexiconClient($http, new ArrayLexiconCache());

        $first = $client->lookup('spoon');
        $second = $client->lookup('spoon');

        self::assertSame(1, $http->calls, 'the second lookup should hit the cache, not Minoo');
        self::assertSame($first->matches[0]->word, $second->matches[0]->word);
    }

    #[Test]
    public function an_empty_query_is_a_clean_miss_and_never_calls_minoo(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, self::SPOON_JSON));
        $result = new LexiconClient($http, new ArrayLexiconCache())->lookup('   ');

        self::assertTrue($result->available);
        self::assertFalse($result->hasMatches());
        self::assertSame(0, $http->calls);
    }
}
