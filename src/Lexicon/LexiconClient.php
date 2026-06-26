<?php

declare(strict_types=1);

namespace App\Lexicon;

use Waaseyaa\HttpClient\HttpClientInterface;

/**
 * Server-side client for Minoo's Anishinaabemowin language API.
 *
 * Minoo's lookup endpoint has NO CORS and serves community-governed (OCAP)
 * Sagamok corpus content, so it is called from here (the Circle's PHP backend),
 * never from the browser. The injected {@see HttpClientInterface} carries a short
 * timeout (see the wiring in AppServiceProvider) so a slow Minoo cannot stall a
 * page.
 *
 * Two invariants make this safe to drop into any page:
 *  1. FAIL-SOFT. If Minoo is slow, down, returns a non-200, or returns
 *     unparseable JSON, {@see self::lookup()} returns
 *     {@see LexiconResult::unavailable()} (available = false). It never throws
 *     and never hangs, so an rhtcircle page always renders.
 *  2. CACHE REAL ANSWERS ONLY. A successful response (match or clean miss) is
 *     cached by (q, tag, dir); an unavailable result is never cached, so a
 *     transient outage is retried rather than pinned for the whole TTL.
 *
 * The endpoint contract is fixed on the Minoo side:
 *   GET {base}/lookup?q=<term>&tag=<bcp47>&dir=en|oj
 */
final class LexiconClient
{
    /** Default base URL; overridden by MINOO_LANG_API_URL in config. */
    public const string DEFAULT_BASE_URL = 'https://minoo.live/api/lang';

    /**
     * The Circle's dialect. The endpoint serves only the Sagamok corpus, so
     * tagging the request makes the contract explicit and matches the label the
     * page renders ("Nishnaabemwin (Sagamok)"). A blank tag omits the parameter.
     */
    public const string DEFAULT_TAG = 'oj-x-sagamok';

    /** The corpus changes slowly: cache a real match for a day. */
    private const int HIT_TTL_SECONDS = 86400;

    /** A clean miss may become a hit as the small corpus grows: re-check hourly. */
    private const int MISS_TTL_SECONDS = 3600;

    /** Guard against an absurdly long query reaching the wire. */
    private const int MAX_QUERY_LENGTH = 120;

    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LexiconCacheInterface $cache,
        ?string $baseUrl = null,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
    }

    /**
     * Look up a term. `$dir` narrows the search direction (en|oj); blank searches
     * both. `$tag` defaults to the Sagamok dialect; blank omits it. Always returns
     * a result, never throws (fail-soft).
     */
    public function lookup(string $query, string $tag = self::DEFAULT_TAG, string $dir = ''): LexiconResult
    {
        $query = mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH);
        $tag = trim($tag);
        $dir = trim($dir);

        if ($query === '') {
            // Nothing to ask Minoo; an empty query is not a failure.
            return new LexiconResult(true, 'miss', '', $tag, $dir, 0, [], null);
        }

        $key = $this->cacheKey($query, $tag, $dir);

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            $decoded = $this->decode($cached);
            if ($decoded !== null) {
                return LexiconResult::fromUpstream($decoded);
            }
        }

        return $this->fetch($query, $tag, $dir, $key);
    }

    private function fetch(string $query, string $tag, string $dir, string $key): LexiconResult
    {
        $params = ['q' => $query];
        if ($tag !== '') {
            $params['tag'] = $tag;
        }
        if ($dir !== '') {
            $params['dir'] = $dir;
        }
        $url = $this->baseUrl . '/lookup?' . http_build_query($params);

        try {
            $response = $this->http->get($url, ['Accept' => 'application/json']);

            // Non-200 (incl. 422 for a malformed request) carries no usable
            // answer: fail soft and do NOT cache.
            if (!$response->isSuccess()) {
                return LexiconResult::unavailable($query, $tag, $dir);
            }

            $decoded = $this->decode($response->body);
            if ($decoded === null) {
                return LexiconResult::unavailable($query, $tag, $dir);
            }

            $result = LexiconResult::fromUpstream($decoded);
            $this->cache->put(
                $key,
                $response->body,
                $result->hasMatches() ? self::HIT_TTL_SECONDS : self::MISS_TTL_SECONDS,
            );

            return $result;
        } catch (\Throwable) {
            // Connection refused, timeout, DNS failure, anything: never break the
            // page. The unavailable result is not cached, so the next request retries.
            return LexiconResult::unavailable($query, $tag, $dir);
        }
    }

    /** @return array<string, mixed>|null */
    private function decode(string $body): ?array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function cacheKey(string $query, string $tag, string $dir): string
    {
        return hash('sha256', mb_strtolower($query) . '|' . $tag . '|' . $dir);
    }
}
