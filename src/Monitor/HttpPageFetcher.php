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

    private ?float $lastRequestAt = null;

    public function __construct(private readonly string $origin = 'https://www.sagamokanishnawbek.com/') {}

    public function fetch(string $url): FetchResult
    {
        // Refuse off-origin before making any request at all. The collector
        // filters too; this is the second lock, because a redirect chain or a
        // future caller could otherwise reach a host we never intended.
        if (!UrlNormalizer::isSameOrigin($url, $this->origin)) {
            return FetchResult::failure($url, 'refused: off-origin');
        }

        $this->throttle();

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'follow_location' => 1,
                'max_redirects' => 5,
                'ignore_errors' => true,
                'header' => [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: text/html,application/xhtml+xml,application/pdf;q=0.8,*/*;q=0.5',
                ],
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return FetchResult::failure($url, 'connection failed');
        }

        // Read with a hard ceiling: an oversize response is abandoned, not
        // truncated, because half a document hashes to something meaningless
        // and would register as a change on every run.
        $body = '';
        while (!feof($handle)) {
            $chunk = fread($handle, 65_536);
            if ($chunk === false) {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                fclose($handle);

                return FetchResult::failure($url, 'response exceeded the 25 MB ceiling');
            }
        }

        $meta = stream_get_meta_data($handle);
        fclose($handle);

        [$status, $headers, $finalUrl] = $this->parseMeta($meta, $url);

        if (!UrlNormalizer::isSameOrigin($finalUrl, $this->origin)) {
            // A redirect took us off-origin. Nothing is retained.
            return FetchResult::failure($url, 'refused: redirected off-origin');
        }

        return FetchResult::success($status, $finalUrl, $body, $headers);
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
     * @return array{int, array<string, string>, string}
     */
    private function parseMeta(array $meta, string $requestedUrl): array
    {
        $status = 0;
        $headers = [];
        $finalUrl = $requestedUrl;

        foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
            $line = (string) $line;
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $headers[$name] = trim($parts[1]);
                if ($name === 'location') {
                    $finalUrl = $headers[$name];
                }
            }
        }

        return [$status, $headers, $finalUrl];
    }
}
