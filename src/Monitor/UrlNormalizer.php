<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * Normalizes a public URL into the collector's stable identity (spec §4.2).
 *
 * `item_key` is `sha256(normalized_url)` and lives in the side table only, never
 * on an entity. Normalization is what makes `?utm_source=…` variants one item
 * rather than many, so duplicate detection is **structural** — the side table's
 * primary key does the work rather than a heuristic.
 *
 * Pure and unit-tested. No I/O.
 */
final class UrlNormalizer
{
    /**
     * Tracking parameters dropped before hashing. Deliberately a fixed list:
     * a pattern like `^utm_` would also eat a legitimate `utm_policy_id`.
     */
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', 'source',
        '_ga', '_gl', 'igshid', 'si',
    ];

    /**
     * Normalize for identity: lowercase host, drop the fragment, strip tracking
     * parameters, collapse a trailing slash.
     *
     * The scheme is preserved rather than forced to https: an http→https move is
     * a real change to a public URL and the operator should see it, not have it
     * silently folded away.
     */
    public static function normalize(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['host'])) {
            // Not absolute — return a trimmed, fragment-free form so a relative
            // link still yields a stable key rather than throwing mid-run.
            return rtrim(explode('#', trim($url))[0], '/');
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        // Default ports carry no identity.
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        $path = $parts['path'] ?? '/';
        // Collapse a trailing slash, but never collapse the root itself away.
        $path = $path === '/' ? '/' : rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        $query = self::normalizeQuery($parts['query'] ?? '');

        return $scheme . '://' . $host
            . ($port !== null ? ':' . $port : '')
            . $path
            . ($query !== '' ? '?' . $query : '');
    }

    /**
     * The collector's identity key for an item. Side table only (spec §3.6):
     * this value must never reach an entity field, a listing facet, or a
     * projection.
     */
    public static function itemKey(string $url): string
    {
        return hash('sha256', self::normalize($url));
    }

    /**
     * Whether $url is on the same origin as $origin. Same-origin is the only
     * link the collector follows (spec §0): scheme, host and port must match
     * after normalization.
     */
    public static function isSameOrigin(string $url, string $origin): bool
    {
        $a = parse_url(self::normalize($url));
        $b = parse_url(self::normalize($origin));

        if (!is_array($a) || !is_array($b) || !isset($a['host'], $b['host'])) {
            return false;
        }

        return strtolower($a['scheme'] ?? '') === strtolower($b['scheme'] ?? '')
            && strtolower($a['host']) === strtolower($b['host'])
            && ($a['port'] ?? null) === ($b['port'] ?? null);
    }

    /**
     * Sort remaining parameters so `?a=1&b=2` and `?b=2&a=1` are one item, and
     * drop the tracking set entirely.
     */
    private static function normalizeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = urldecode(explode('=', $pair, 2)[0]);
            if (in_array(strtolower($name), self::TRACKING_PARAMS, true)) {
                continue;
            }
            $pairs[] = $pair;
        }

        sort($pairs);

        return implode('&', $pairs);
    }
}
