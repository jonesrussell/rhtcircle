<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * Normalizes fetched HTML before hashing (spec §4.3, "Content normalization").
 *
 * Strips what changes on every fetch without the content changing: session ids,
 * CSRF tokens, cache-buster query strings, footer timestamps, rotating asset
 * hashes. Un-normalized hashing yields "everything changed, every hour", which
 * carries exactly as much information as no signal at all — and on a dashboard
 * about an accountability process, a wall of false changes is worse than
 * silence, because it trains readers to ignore the real one.
 *
 * Pure and unit-tested. No I/O.
 */
final class ContentNormalizer
{
    /** Snapshot cap from the approved limits (spec §0): 2 MB. */
    public const int MAX_SNAPSHOT_BYTES = 2 * 1024 * 1024;

    /**
     * Volatile patterns removed before hashing, as regex => replacement. Each
     * one is here because leaving it in produces a change event on every run.
     *
     * @var array<string, string>
     */
    private const VOLATILE = [
        // Session / anti-forgery tokens in markup or URLs.
        '/\b(PHPSESSID|JSESSIONID|ASP\.NET_SessionId|sid|session_id)=[^"\'\s&;<>]+/i' => '$1=',
        '/<input[^>]+name=["\'](?:csrf[_-]?token|_token|authenticity_token|__RequestVerificationToken)["\'][^>]*>/i' => '',
        '/\b(?:csrf[_-]?token|nonce)["\']?\s*[:=]\s*["\'][^"\']+["\']/i' => '',
        // Cache busters and rotating asset fingerprints.
        '/([?&](?:v|ver|version|cb|cache|_|t|rev)=)[A-Za-z0-9._-]+/i' => '$1',
        '/([\w\/-]+)\.[0-9a-f]{8,32}\.(css|js|woff2?|png|jpe?g|svg)\b/i' => '$1.$2',
        // Generated-at / last-updated stamps that track the fetch, not the page.
        '/\b(?:generated|rendered|page\s+generated|last\s+updated)\s*(?:on|at)?[:\s][^<\n]{0,40}/i' => '',
        '/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?/' => '',
        // Server-side render timings some CMSs emit in a comment.
        '/<!--[^>]*?(?:page\s+served|render(?:ed)?\s+in|query\s+count)[^>]*?-->/is' => '',
    ];

    /**
     * Normalize $html for hashing.
     *
     * Comments and script/style bodies go first: they are the densest source of
     * per-request noise, and nothing in the dashboard's promise depends on them.
     */
    public static function normalize(string $html): string
    {
        $text = $html;

        $text = (string) preg_replace('/<!--.*?-->/s', '', $text);
        $text = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $text);
        $text = (string) preg_replace('#<style\b[^>]*>.*?</style>#is', '', $text);

        foreach (self::VOLATILE as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        // Collapse all whitespace last, so the substitutions above cannot leave
        // a hash that differs only by the gap they left behind.
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * The content hash the collector compares run to run (spec §4.3 step 2).
     */
    public static function hash(string $html): string
    {
        return hash('sha256', self::normalize($html));
    }

    /**
     * The snapshot stored in the side table, truncated to the approved 2 MB cap.
     *
     * Truncation is on the **normalized** text, so a snapshot is always
     * consistent with the hash beside it.
     */
    public static function snapshot(string $html): string
    {
        $normalized = self::normalize($html);

        return strlen($normalized) > self::MAX_SNAPSHOT_BYTES
            ? substr($normalized, 0, self::MAX_SNAPSHOT_BYTES)
            : $normalized;
    }
}
