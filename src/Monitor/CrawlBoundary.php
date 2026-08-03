<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * The single authority on what this monitor may request, parse, hash, title,
 * snapshot or project.
 *
 * ## Why this class exists
 *
 * There used to be two lists. `LinkExtractor::NEVER_FOLLOW` named the portal
 * path family (`/members`, `/member-portal`, `/portal`, `/account`, `/admin`)
 * and stopped the crawler *queuing* them. `GateDetector::LOGIN_PATH_MARKERS`
 * named only `/members/login` and `/account/login` and classified what had
 * *already been fetched*. The gate is the list that runs after a request, and
 * it did not know what the other list knew.
 *
 * That gap was reachable end to end: a public page links to `/news/bulletin`,
 * the crawler queues it (not a NEVER_FOLLOW path), the fetcher requests it, and
 * the server 302s to `/members/bulletin`. Same origin, so the redirect was
 * followed. The response is 200 with a members-area body — no password input,
 * over the login-shell size ceiling, and `/members` absent from the gate's
 * list. `reason()` returned `null`. The collector then hashed it, wrote up to
 * 2 MB of members-only body into `monitor_collector_snapshot`, and set the
 * item title from the portal page's `<title>` — a field that is in the public
 * projection and rendered on the dashboard.
 *
 * One list, consulted at every stage, is the fix. Discovery, redirect
 * processing, final effective-URL validation, and gating all ask this class.
 *
 * ## What "protected" means here
 *
 * A path family the monitor treats as members-only **by policy**, whether or
 * not the server happens to enforce it on any given day. The monitor never
 * requests these, never retains anything from them, and never lets a value
 * derived from them reach storage or a reader. This is deliberately
 * independent of HTTP status: a portal page served at 200 to an anonymous
 * client — a misconfiguration on the publisher's side — is still not ours to
 * collect. That is precisely the failure the original spec was written around.
 */
final class CrawlBoundary
{
    /**
     * Members-only path families. Matched on **whole path segments** after
     * normalization, so `/membership` and `/accounts-payable` are public pages
     * and `/members`, `/members/`, `/Members/News`, `/members.php` are not.
     *
     * Ordering is irrelevant; this is a set.
     */
    private const PROTECTED_PREFIXES = [
        // The portal family proper.
        '/members', '/member-portal', '/memberportal', '/member',
        '/portal', '/account', '/accounts', '/my-account',
        '/admin', '/administrator', '/dashboard',
        // Authentication endpoints. A URL that sends us here is telling us the
        // thing behind it is not public.
        '/login', '/signin', '/sign-in', '/signon', '/sign-on',
        '/auth', '/oauth', '/sso', '/saml', '/logout', '/register',
        '/password', '/reset-password', '/forgot-password',
        // Platform back ends.
        '/wp-admin', '/wp-login', '/user/login', '/user/register',
        '/administrator/index.php',
    ];

    /**
     * Not protected, but never worth crawling: transactional endpoints that
     * are not public content and can have side effects when requested.
     * Discovery skips these; they are not a retention boundary.
     */
    private const SKIP_DISCOVERY_PREFIXES = [
        '/cart', '/checkout', '/basket', '/search', '/print',
    ];

    /** Bounded, because normalization must terminate on hostile input. */
    private const MAX_DECODE_PASSES = 3;

    // ------------------------------------------------------------------
    // The questions callers ask
    // ------------------------------------------------------------------

    /**
     * Whether this URL is inside a members-only family.
     *
     * The monitor must never request, parse, hash, title, snapshot or project
     * anything for which this returns true.
     */
    public static function isProtected(string $url): bool
    {
        return self::matchesAny(self::normalizePath($url), self::PROTECTED_PREFIXES);
    }

    /** Whether discovery should decline to queue this URL at all. */
    public static function isExcludedFromDiscovery(string $url): bool
    {
        $path = self::normalizePath($url);

        return self::matchesAny($path, self::PROTECTED_PREFIXES)
            || self::matchesAny($path, self::SKIP_DISCOVERY_PREFIXES);
    }

    /**
     * Defense in depth. Call immediately before doing anything with a fetched
     * response, so that a boundary miss anywhere upstream fails loudly here
     * rather than silently producing a retained copy of protected material.
     *
     * @throws ProtectedResourceException
     */
    public static function assertPublic(string $url, string $stage): void
    {
        if (self::isProtected($url)) {
            throw new ProtectedResourceException($url, $stage);
        }
    }

    // ------------------------------------------------------------------
    // Normalization
    // ------------------------------------------------------------------

    /**
     * The comparable path for a URL.
     *
     * Every step here exists to close a way of writing `/members` that would
     * not literally read as `/members`:
     *
     *  - percent-encoding      `/%6Dembers` and `/%2Fmembers`
     *  - repeated encoding     `/%256Dembers` (bounded passes)
     *  - backslash separators  `\members` (some servers treat these as `/`)
     *  - case                  `/Members`
     *  - duplicate slashes     `//members`
     *  - dot segments          `/./members`, `/public/../members`
     *  - trailing slash        `/members/`
     *
     * Decoding is applied repeatedly up to a bound and the result is checked at
     * every pass by the caller's matcher, so a value that only *becomes*
     * protected after further decoding is still caught. Over-matching is the
     * safe direction here: the cost of refusing a public page is a gap in
     * monitoring, and the cost of the reverse is retaining members-only
     * material.
     */
    public static function normalizePath(string $url): string
    {
        $path = parse_url(trim($url), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            // A bare origin, or something unparseable. Root is public; an
            // unparseable value is not something we should request either way.
            $path = '/';
        }

        return self::canonicalize($path);
    }

    /** @param list<string> $prefixes */
    private static function matchesAny(string $path, array $prefixes): bool
    {
        // Check the path as given, then after each further decode pass. A URL
        // whose protected nature only surfaces after double-decoding is still
        // refused.
        $candidate = $path;
        for ($pass = 0; $pass <= self::MAX_DECODE_PASSES; ++$pass) {
            foreach ($prefixes as $prefix) {
                if (self::matchesPrefix($candidate, $prefix)) {
                    return true;
                }
            }

            $decoded = self::canonicalize(rawurldecode($candidate));
            if ($decoded === $candidate) {
                break;
            }
            $candidate = $decoded;
        }

        return false;
    }

    /**
     * Whole-segment prefix match.
     *
     * `str_starts_with()` alone would make `/membership` a protected path,
     * which would quietly stop the monitor watching a legitimate public page.
     * The boundary must be a segment boundary, an extension dot, or the end of
     * the path.
     */
    private static function matchesPrefix(string $path, string $prefix): bool
    {
        return $path === $prefix
            || str_starts_with($path, $prefix . '/')
            || str_starts_with($path, $prefix . '.');
    }

    /** Lowercase, decode once, collapse separators, resolve dot segments. */
    private static function canonicalize(string $path): string
    {
        $path = strtolower(rawurldecode($path));
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $resolved = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);
                continue;
            }
            $resolved[] = $segment;
        }

        return '/' . implode('/', $resolved);
    }
}
