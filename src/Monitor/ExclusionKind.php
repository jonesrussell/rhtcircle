<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * Why the collector declined to collect or retain a page.
 *
 * Two genuinely different findings, kept apart everywhere — in events, in
 * source health, and in dashboard wording:
 *
 *  - {@see self::AuthRequired} — the page now demands authentication (401, 403,
 *    a redirect to a login path, or a login shell served at 200). This is a
 *    statement about **access**: material that was public is now behind a
 *    login.
 *  - {@see self::NotForRetention} — the page asks not to be indexed or
 *    retained (`noindex`, by header or meta tag). This is a statement about
 *    **our collection**, and says nothing at all about whether the page is
 *    publicly reachable. It very often still is.
 *
 * Conflating them would publish a false claim. A `noindex` page reported as
 * "now requires login" tells members the Nation restricted access when it did
 * not — and on a dashboard whose entire purpose is accountability, an
 * inaccurate accusation is the most damaging output it can produce.
 *
 * In both cases the collector stores no body, no hash and no snapshot.
 *
 * @api
 */
enum ExclusionKind: string
{
    /** Access is gated: authentication is now required. */
    case AuthRequired = 'auth_required';

    /** The page asks not to be collected or retained. Access is unchanged. */
    case NotForRetention = 'not_for_retention';

    /**
     * The `monitor_event` type recorded for this exclusion.
     */
    public function eventType(): string
    {
        return match ($this) {
            self::AuthRequired => 'became_gated',
            self::NotForRetention => 'became_noindex',
        };
    }

    /**
     * Whether this exclusion should degrade source health.
     *
     * `noindex` must NOT: the site is reachable and behaving normally, and we
     * are choosing not to retain the page. Flagging the source unhealthy for
     * honouring a publisher's directive would be a false alarm about our own
     * monitoring.
     */
    public function affectsSourceHealth(): bool
    {
        return $this === self::AuthRequired;
    }

    /**
     * Dashboard wording. Deliberately neutral and factual for
     * {@see self::NotForRetention}: it describes what we did, not what the
     * publisher did wrong.
     */
    public function publicLabel(): string
    {
        return match ($this) {
            self::AuthRequired => 'Now requires sign-in',
            self::NotForRetention => 'Not retained at publisher request',
        };
    }
}
