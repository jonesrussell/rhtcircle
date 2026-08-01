<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * Decides whether a fetched page is still genuinely public (spec §4.3,
 * "Re-gating gate").
 *
 * This runs **before** hashing or storing anything. It exists because the
 * portal's original defect was a **200 response with a client-side login
 * overlay** — status code alone would have called that page public. Without
 * this check, a page moved behind the members portal would be fetched at 200,
 * hashed, and snapshotted, quietly turning a public-website monitor into a copy
 * of protected material.
 *
 * A gated observation is a **finding to report**, not content to collect: the
 * collector records `became_gated` and stores no body, no hash and no snapshot.
 *
 * Pure and unit-tested. No I/O — it inspects a response the fetcher already has.
 */
final class GateDetector
{
    /**
     * Path fragments that mean "you have been sent to authenticate". Matched
     * against the *final* URL after redirects.
     */
    private const LOGIN_PATH_MARKERS = [
        '/login', '/signin', '/sign-in', '/auth', '/account/login',
        '/members/login', '/wp-login', '/user/login', '/sso', '/saml',
    ];

    /**
     * Markers of a login shell served at 200. Deliberately conservative: each
     * one alone is weak evidence, so {@see isGated()} requires corroboration
     * from a short body, which is what a login shell is.
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
    public static function isGated(int $statusCode, string $finalUrl, string $body, array $headers = []): bool
    {
        return self::reason($statusCode, $finalUrl, $body, $headers) !== null;
    }

    /**
     * Why the page is gated, or null when it is genuinely public.
     *
     * The reason is recorded on the event so an operator can tell "the site
     * removed this" from "the site put this behind a login" — very different
     * findings that a boolean would flatten together.
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

        if ($statusCode === 200 && self::carriesNoindex($body, $headers)) {
            return 'noindex';
        }

        if ($statusCode === 200 && self::looksLikeLoginShell($body)) {
            return 'login_shell';
        }

        return null;
    }

    private static function looksLikeLoginPath(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        if ($path === '') {
            return false;
        }

        foreach (self::LOGIN_PATH_MARKERS as $marker) {
            if (str_starts_with($path, $marker) || str_contains($path, $marker . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * `noindex` from either the header or the meta tag. A page the site asks
     * search engines not to index is not a page we treat as published.
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
