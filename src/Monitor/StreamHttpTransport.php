<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * The production transport: one PHP stream request, no redirect following.
 *
 * Extracted verbatim from {@see HttpPageFetcher}'s former private `request()`
 * so the redirect boundary can be proven at this seam. Behaviour is unchanged;
 * only the location moved.
 *
 * Records nothing. See {@see HttpTransportInterface} for why.
 */
final class StreamHttpTransport implements HttpTransportInterface
{
    public function send(string $url, int $timeoutSeconds, string $userAgent, int $maxBytes): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                // The load-bearing lines: the transport never fetches a second
                // URL on its own, so every hop passes back through the caller's
                // boundary checks before it can be requested.
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
                'header' => [
                    'User-Agent: ' . $userAgent,
                    'Accept: text/html,application/xhtml+xml,application/pdf;q=0.8,*/*;q=0.5',
                ],
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
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
            if (strlen($body) > $maxBytes) {
                $error = 'response exceeded the 25 MB ceiling';
                $body = '';
                break;
            }
        }

        $meta = stream_get_meta_data($handle);
        fclose($handle);

        [$status, $headers] = self::parseMeta($meta);

        return [$status, $headers, $body, $error];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{int, array<string, string>}
     */
    private static function parseMeta(array $meta): array
    {
        $status = 0;
        $headers = [];

        $wrapperData = $meta['wrapper_data'] ?? [];
        if (!is_array($wrapperData)) {
            return [$status, $headers];
        }

        foreach ($wrapperData as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                // Last status wins: a chain of headers can carry several.
                $status = (int) $m[1];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }

        return [$status, $headers];
    }
}
