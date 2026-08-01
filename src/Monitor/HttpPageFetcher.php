<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * The production fetcher, honouring the approved crawl limits (spec §0).
 *
 * Constructed in exactly one place — the console-command handler in
 * `SagamokMonitorServiceProvider` — and never in a test. Every test drives
 * {@see FixturePageFetcher} instead, so a test run cannot reach the production
 * Sagamok site even by mistake.
 *
 * Limits, all of them deliberate rather than defaults:
 *
 *  - one request per second, enforced between calls;
 *  - 15 second timeout;
 *  - 25 MB maximum response, abandoned rather than truncated;
 *  - same-origin redirects only;
 *  - a clear user agent naming this project and a contact URL, so the Nation's
 *    operators can see who is requesting and tell us to stop.
 *
 * No credential, cookie jar, auth header or archive client appears here, and
 * `PortalBoundaryTest` greps this tree to keep it that way.
 */
final class HttpPageFetcher implements PageFetcherInterface
{
    public const string USER_AGENT = 'RHTCircleMonitor/1.0 (+https://rhtcircle.ca/communities/sagamok/monitor)';
    public const int TIMEOUT_SECONDS = 15;
    public const int MAX_RESPONSE_BYTES = 25 * 1024 * 1024;
    public const int MIN_INTERVAL_MICROSECONDS = 1_000_000;
    public const int MAX_REDIRECTS = 5;

    private ?float $lastRequestAt = null;

    public function __construct(private readonly string $origin = 'https://www.sagamokanishnawbek.com/') {}

    public function fetch(string $url): FetchResult
    {
        $target = $url;

        // Follow redirects BY HAND. `follow_location` would have the transport
        // request the next hop before we could look at it, so an off-origin
        // redirect target receives a request from us and only then gets
        // rejected — which is precisely the thing we promise never to do. The
        // check has to happen between hops, not after them.
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            if (!UrlNormalizer::isSameOrigin($target, $this->origin)) {
                return FetchResult::failure($url, $hop === 0 ? 'refused: off-origin' : 'refused: redirect left the origin');
            }

            $response = $this->request($target);
            if ($response === null) {
                return FetchResult::failure($url, 'connection failed');
            }

            [$status, $headers, $body, $error] = $response;
            if ($error !== null) {
                return FetchResult::failure($url, $error);
            }

            if (!self::isRedirect($status) || !isset($headers['location'])) {
                return FetchResult::success($status, $target, $body, $headers);
            }

            $next = self::resolveLocation($target, $headers['location']);
            if ($next === null) {
                return FetchResult::failure($url, 'redirect Location could not be resolved');
            }
            if ($next === $target) {
                return FetchResult::failure($url, 'redirect loop: Location points at itself');
            }

            $target = $next;
        }

        // Hop limit reached without a terminal response.
        return FetchResult::failure($url, 'too many redirects');
    }

    /**
     * One request, no automatic redirect following.
     *
     * @return array{int, array<string, string>, string, ?string}|null
     */
    private function request(string $target): ?array
    {
        $this->throttle();

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                // The load-bearing line: nothing is fetched that we have not
                // already validated as same-origin.
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
                'header' => [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: text/html,application/xhtml+xml,application/pdf;q=0.8,*/*;q=0.5',
                ],
            ],
        ]);

        $handle = @fopen($target, 'rb', false, $context);
        if ($handle === false) {
            return null;
        }

        // Read with a hard ceiling: an oversize response is abandoned, not
        // truncated, because half a document hashes to something meaningless
        // and would register as a change on every run.
        $body = '';
        $error = null;
        while (!feof($handle)) {
            $chunk = fread($handle, 65_536);
            if ($chunk === false) {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                $error = 'response exceeded the 25 MB ceiling';
                $body = '';
                break;
            }
        }

        $meta = stream_get_meta_data($handle);
        fclose($handle);

        [$status, $headers] = $this->parseMeta($meta);

        return [$status, $headers, $body, $error];
    }

    private static function isRedirect(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], true);
    }

    /**
     * Resolve a `Location` value against the URL that produced it.
     *
     * Handles the three shapes a server may send: absolute, scheme-relative
     * (`//host/path`) and path-relative. A relative Location resolved wrongly
     * would either miss a real redirect or, worse, produce a URL on a different
     * host that the same-origin check then has to catch.
     */
    private static function resolveLocation(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        $baseParts = parse_url($base);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        if (str_starts_with($location, '//')) {
            return $baseParts['scheme'] . ':' . $location;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $authority = $baseParts['scheme'] . '://' . $baseParts['host']
            . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }

        // Path-relative: resolve against the base's directory.
        $basePath = $baseParts['path'] ?? '/';
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        if ($directory === '') {
            $directory = '/';
        }

        return $authority . $directory . $location;
    }

    private function throttle(): void
    {
        if ($this->lastRequestAt !== null) {
            $elapsed = (int) ((microtime(true) - $this->lastRequestAt) * 1_000_000);
            if ($elapsed < self::MIN_INTERVAL_MICROSECONDS) {
                usleep(self::MIN_INTERVAL_MICROSECONDS - $elapsed);
            }
        }
        $this->lastRequestAt = microtime(true);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{int, array<string, string>}
     */
    private function parseMeta(array $meta): array
    {
        $status = 0;
        $headers = [];

        foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
            $line = (string) $line;
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                // Last status wins if the transport ever reports several.
                $status = (int) $m[1];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return [$status, $headers];
    }
}
