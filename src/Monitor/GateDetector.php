<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * Decides whether a fetched page may still be collected (spec §4.3,
 * "Re-gating gate").
 *
 * Returns an {@see ExclusionKind}, because there are **two** distinct reasons
 * to decline and they must never be reported as one another:
 * `AuthRequired` (the page now demands a login) and `NotForRetention`
 * (`noindex` — the publisher asks us not to retain it, while the page itself
 * may remain perfectly public).
 *
 * This runs **before** hashing or storing anything. It exists because the
 * portal's original defect was a **200 response with a client-side login
 * overlay** — status code alone would have called that page public. Without
 * this check, a page moved behind the members portal would be fetched at 200,
 * hashed, and snapshotted, quietly turning a public-website monitor into a copy
 * of protected material.
 *
 * Either way the observation is a **finding to report**, not content to
 * collect: the collector stores no body, no hash and no snapshot, and records
 * the event type belonging to the kind ({@see ExclusionKind::eventType()}).
 *
 * Pure and unit-tested. No I/O — it inspects a response the fetcher already has.
 */
final class GateDetector
{
    /**
     * Markers of a login shell served at 200. Deliberately conservative: each
     * one alone is weak evidence, so {@see looksLikeLoginShell()} requires
     * corroboration from a short body, which is what a login shell is.
     */
    private const LOGIN_BODY_MARKERS = [
        'type="password"',
        "type='password'",
        'name="password"',
        'id="password"',
        'members only',
        'member login',
        'please log in',
        'please sign in',
        'you must be logged in',
        'authentication required',
        'restricted area',
    ];

    /**
     * A body this short is not a public page worth monitoring; combined with a
     * login marker it is a login shell.
     */
    private const LOGIN_SHELL_MAX_BYTES = 60_000;

    /**
     * @param int $statusCode HTTP status of the final response.
     * @param string $finalUrl URL after following same-origin redirects.
     * @param string $body Raw response body.
     * @param array<string, string> $headers Response headers, lowercase keys.
     */
    public static function isExcluded(int $statusCode, string $finalUrl, string $body, array $headers = []): bool
    {
        return self::classify($statusCode, $finalUrl, $body, $headers) !== null;
    }

    /**
     * The exclusion kind, or null when the page may be collected.
     *
     * @param array<string, string> $headers Response headers, lowercase keys.
     */
    public static function classify(int $statusCode, string $finalUrl, string $body, array $headers = []): ?ExclusionKind
    {
        $reason = self::reason($statusCode, $finalUrl, $body, $headers);
        if ($reason === null) {
            return null;
        }

        return $reason === 'noindex' ? ExclusionKind::NotForRetention : ExclusionKind::AuthRequired;
    }

    /**
     * The specific reason, or null when the page may be collected.
     *
     * Recorded on the event so an operator can tell "the site removed this"
     * from "the site put this behind a login" from "the publisher asked us not
     * to retain it" — three different findings a boolean would flatten into
     * one, at least one of which would then be an accusation we cannot support.
     *
     * @param array<string, string> $headers Response headers, lowercase keys.
     */
    public static function reason(int $statusCode, string $finalUrl, string $body, array $headers = []): ?string
    {
        if ($statusCode === 401 || $statusCode === 403) {
            return 'http_' . $statusCode;
        }

        if (self::looksLikeLoginPath($finalUrl)) {
            return 'redirected_to_login';
        }

        // Login shell before noindex: a gated page that ALSO carries noindex
        // is an access change first and foremost, and reporting it as a mere
        // retention preference would understate what happened.
        if ($statusCode === 200 && self::looksLikeLoginShell($body)) {
            return 'login_shell';
        }

        if ($statusCode === 200 && self::carriesNoindex($body, $headers)) {
            return 'noindex';
        }

        return null;
    }

    /**
     * Delegates to {@see CrawlBoundary} — the single authority (review finding 1).
     *
     * This method used to own a list naming only `/members/login` and
     * `/account/login`, while `LinkExtractor` separately knew about the whole
     * `/members` family. The gate is what runs *after* a fetch, so its shorter
     * list is the one that decided whether a members-area page reached the
     * hasher, the snapshot table and the item title. A redirect to
     * `/members/bulletin` — no password input, over the login-shell size
     * ceiling — was classified as ordinary public content.
     *
     * Its old prefix test was also a bare `str_starts_with()`, which would
     * have called `/logins-explained` a login page. `CrawlBoundary` matches on
     * whole segments.
     */
    private static function looksLikeLoginPath(string $url): bool
    {
        return CrawlBoundary::isProtected($url);
    }

    /**
     * `noindex` from either the header or the meta tag.
     *
     * This means "do not collect or retain this page". It does NOT mean the
     * page requires authentication — a noindex page is usually still publicly
     * reachable — so it maps to {@see ExclusionKind::NotForRetention} and is
     * kept out of every gated/sign-in code path, counter and label.
     *
     * @param array<string, string> $headers
     */
    private static function carriesNoindex(string $body, array $headers): bool
    {
        $robotsHeader = strtolower($headers['x-robots-tag'] ?? '');
        if (str_contains($robotsHeader, 'noindex')) {
            return true;
        }

        return preg_match(
            '/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex/i',
            $body,
        ) === 1;
    }

    /**
     * A login marker in a short body. Both halves are required: a long article
     * that happens to mention "please log in" is still a public article, and a
     * short page with no login marker is just a short page.
     */
    private static function looksLikeLoginShell(string $body): bool
    {
        if (strlen($body) > self::LOGIN_SHELL_MAX_BYTES) {
            return false;
        }

        $haystack = strtolower($body);
        foreach (self::LOGIN_BODY_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }
}
