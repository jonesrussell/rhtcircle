<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * Extracts same-origin public links from a fetched page (spec §0, discovery).
 *
 * This is what makes the monitor find **new** public posts rather than
 * re-checking a frozen list forever. A fixed URL list would report changes to
 * pages we already knew about and stay silent about everything published after
 * the list was written, which is close to useless for the thing the dashboard
 * exists to do.
 *
 * Discovery is deliberately narrow:
 *
 *  - same-origin only, resolved before the link is ever queued;
 *  - `rel="nofollow"` honoured;
 *  - fragments, `mailto:`, `tel:`, `javascript:` and query-only links dropped;
 *  - anything that looks like a members-portal or login path is **never**
 *    queued, so a stray link on a public page cannot walk the crawler into
 *    access-controlled territory.
 *
 * Pure and unit-tested. No I/O.
 */
final class LinkExtractor
{
    /** Extensions worth following. Anything else is an asset, not a document. */
    private const FOLLOWABLE_EXTENSIONS = ['', 'html', 'htm', 'php', 'asp', 'aspx', 'pdf'];

    /**
     * @return list<string> Normalized, same-origin, de-duplicated URLs.
     */
    public static function extract(string $html, string $baseUrl, string $origin): array
    {
        if (!preg_match_all('#<a\b([^>]*)>#i', $html, $matches)) {
            return [];
        }

        $found = [];
        foreach ($matches[1] as $attributes) {
            if (preg_match('#\brel\s*=\s*["\']?[^"\'>]*nofollow#i', $attributes) === 1) {
                continue;
            }
            if (preg_match('#\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $attributes, $m) !== 1) {
                continue;
            }

            $href = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : ($m[4] ?? ''));
            $resolved = self::resolve(trim(html_entity_decode($href)), $baseUrl);
            if ($resolved === null) {
                continue;
            }
            if (!UrlNormalizer::isSameOrigin($resolved, $origin)) {
                continue;
            }
            if (self::isNeverFollowed($resolved) || !self::isFollowableDocument($resolved)) {
                continue;
            }

            $found[UrlNormalizer::itemKey($resolved)] = UrlNormalizer::normalize($resolved);
        }

        return array_values($found);
    }

    private static function resolve(string $href, string $baseUrl): ?string
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        $scheme = strtolower((string) (parse_url($href, PHP_URL_SCHEME) ?: ''));
        if (in_array($scheme, ['mailto', 'tel', 'javascript', 'data', 'ftp'], true)) {
            return null;
        }

        $href = explode('#', $href)[0];
        if ($href === '') {
            return null;
        }

        if ($scheme !== '') {
            return $href;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            return null;
        }

        $authority = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($href, '//')) {
            return $base['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $authority . $href;
        }

        $path = $base['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $authority . ($directory === '' ? '/' : $directory) . $href;
    }

    private static function isNeverFollowed(string $url): bool
    {
        // One authority (review finding 1). This list used to live here and
        // disagreed with GateDetector's, which is what let a redirect into
        // `/members` be collected: discovery knew the path family, the gate
        // did not, and the gate is what runs after a fetch.
        return CrawlBoundary::isExcludedFromDiscovery($url);
    }

    private static function isFollowableDocument(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $basename = basename($path);

        if (!str_contains($basename, '.')) {
            return true;
        }

        $extension = strtolower(substr($basename, (int) strrpos($basename, '.') + 1));

        return in_array($extension, self::FOLLOWABLE_EXTENSIONS, true);
    }
}
