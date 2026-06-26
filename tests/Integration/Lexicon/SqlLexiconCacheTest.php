<?php

declare(strict_types=1);

namespace App\Tests\Integration\Lexicon;

use App\Lexicon\LexiconCacheSchema;
use App\Lexicon\LexiconClient;
use App\Lexicon\SqlLexiconCache;
use App\Tests\Support\Lexicon\FakeHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\HttpClient\HttpResponse;

/**
 * The SQL-backed cache against a real (in-memory) SQLite, end to end through the
 * client: the schema creates its table, a real answer is cached, and a second
 * identical lookup is served from SQLite without calling Minoo again.
 */
final class SqlLexiconCacheTest extends TestCase
{
    private const string SPOON_JSON = '{"match_type":"exact","query":"spoon","tag":"oj-x-sagamok","count":1,'
        . '"matches":[{"word":"Emkwaan","definition":["spoon"],"label":"Nishnaabemwin (Sagamok)",'
        . '"provenance":{"attribution":"Sagamok community Knowledge Keepers"}}],"usage":{"governance":"OCAP"}}';

    #[Test]
    public function caches_a_real_answer_through_sqlite(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        new LexiconCacheSchema($db)->ensure();

        $http = new FakeHttpClient(new HttpResponse(200, self::SPOON_JSON));
        $client = new LexiconClient($http, new SqlLexiconCache($db));

        $first = $client->lookup('spoon');
        self::assertSame('Emkwaan', $first->matches[0]->word);
        self::assertSame(1, $http->calls);

        // The row is now in SQLite; the second lookup re-parses it.
        $second = $client->lookup('spoon');
        self::assertSame('Emkwaan', $second->matches[0]->word);
        self::assertSame(1, $http->calls, 'second lookup should be served from the SQLite cache');
    }

    #[Test]
    public function a_direct_put_and_get_roundtrips(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        new LexiconCacheSchema($db)->ensure();
        $cache = new SqlLexiconCache($db);

        self::assertNull($cache->get('absent'));

        $cache->put('k', '{"hello":"world"}', 3600);
        self::assertSame('{"hello":"world"}', $cache->get('k'));

        // Writing the same key again replaces the prior value (no row growth).
        $cache->put('k', '{"hello":"again"}', 3600);
        self::assertSame('{"hello":"again"}', $cache->get('k'));
    }
}
