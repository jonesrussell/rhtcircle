# First-party analytics: design (rhtcircle)

Date: 2026-05-24 (original, OIATC) · ported to rhtcircle 2026-06-22
Status: built

Ported from the OIATC implementation. Same architecture and privacy posture,
adapted to this repo: rhtcircle renders through its own plain-Twig `View` layer
(not the SSR package), wires the collector site-wide in `base.html.twig`, and
carries no chatbot, so the chat-query log is omitted.

## Goal

First-party, privacy-respecting analytics owned entirely on hub infrastructure.
Capture pageviews, unique-ish visitors, and engagement (read-depth + dwell).
View it on a protected dashboard. The first job is to see the oiatc.ca redirect
traffic landing on the hub.

## Principles

- First-party only. No third-party JS, no cookies, no raw IP stored, no ad-tech.
- Data sovereignty: everything lives in the app's own SQLite DB.
- Respect Do Not Track / Global Privacy Control; skip known bots.
- Lean core, extensible later.

## Architecture (umami-style beacon)

```
browser ──(JSON beacon)──> POST /api/collect ──> AnalyticsRecorder ──> analytics_event (SQLite)
                                                                              │
Caddy basic_auth /admin/* ──> GET /admin/analytics ──> AnalyticsReport ──(aggregate)─┘ ──> Twig dashboard
```

## Data contract: beacon (client → `POST /api/collect`, `application/json`)

Short keys to keep the payload tiny. CSRF-exempt (JSON content type).

```json
// pageview, sent on load
{ "t": "pageview", "p": "/treaty", "r": "https://oiatc.ca/", "v": "0b9f...uuid" }

// engagement, sent on page hide via navigator.sendBeacon
{ "t": "engagement", "v": "0b9f...uuid", "s": 75, "d": 42000 }
```

| key | meaning | rules |
|-----|---------|-------|
| `t` | event type | `pageview` or `engagement` |
| `p` | path | required for pageview; string, capped 255 |
| `r` | referrer (full URL) | optional; server reduces to host only |
| `v` | view id (client `crypto.randomUUID()`) | required; capped 64 |
| `s` | max scroll percent | engagement; int 0–100 |
| `d` | dwell ms | engagement; int 0–86,400,000 (capped) |

Server derives and stores (never trusts client for these):
- `visitor_hash` = `sha256(daily_salt . '|' . ip . '|' . ua)`, where
  `daily_salt = hash_hmac('sha256', gmdate('Y-m-d'), SECRET)`.
  `SECRET = getenv('WAASEYAA_ANALYTICS_SECRET') ?: getenv('WAASEYAA_JWT_SECRET')`.
  Rotates daily; raw IP/UA never persisted.
- `device` = coarse `mobile` | `tablet` | `desktop` from UA.
- `referrer_host` = `parse_url($r, PHP_URL_HOST)` or null.
- `created_at` = `gmdate('Y-m-d H:i:s')` (UTC).

Recorder rejects (returns false, endpoint still answers 204): unknown `t`,
missing/oversized fields, out-of-range ints, or bot UA.

## Table `analytics_event`

Created on boot in `AppServiceProvider::boot()` via `AnalyticsSchema::ensure()`,
guarded by `schema()->tableExists()` (no migration CLI in the framework). Pinned
to the persistent SQLite file (the same pin-to-file rationale as the petition).

| field | type | notes |
|-------|------|-------|
| id | serial | pk |
| event_type | varchar(20) | |
| path | varchar(255) | null for engagement rows |
| referrer_host | varchar(255) | null |
| view_id | varchar(64) | ties pageview+engagement |
| visitor_hash | varchar(64) | null for engagement rows |
| device | varchar(20) | null |
| scroll_pct | int | null |
| dwell_ms | int | null |
| created_at | varchar(19) | UTC `Y-m-d H:i:s` |

Indexes: `created_at`, `event_type`, `view_id`, `(visitor_hash, created_at)`.

## PHP API

```php
namespace App\Analytics;

final class AnalyticsRecorder {
    public function __construct(\Waaseyaa\Database\DatabaseInterface $db, string $secret) {}
    public function record(array $beacon, ?string $ip, ?string $userAgent): bool;
}

final class AnalyticsReport {
    public function __construct(\Waaseyaa\Database\DatabaseInterface $db) {}
    public function summary(string $fromDate, string $toDate): array;
    public function viewsForPath(string $path): int;
}
```

Controllers:
- `App\Controller\CollectController::collect(Request): Response`: decode JSON, call recorder, return 204.
- `App\Controller\AnalyticsDashboardController::index(Request): Response`: read `?from`/`?to` (default last 30 days), call `AnalyticsReport::summary`, render via `App\Support\View`.
- `App\Controller\PageStatsController::stats(Request): Response`: public per-page all-time view count (JSON) for on-page social proof.

## Routes (AppServiceProvider)

- `analytics.collect`: `POST /api/collect`, `allowAll()`.
- `analytics.page-stats`: `GET /api/page-stats`, `allowAll()`.
- `admin.analytics`: `GET /admin/analytics`, `allowAll()` at app layer (Caddy gates it).

## Dashboard view: `templates/admin/analytics.html.twig`

Standalone HTML document (its own `<html>`, `noindex`), styled with the
rhtcircle palette (indigo/magenta, Fraunces + Inter, the rainbow rule). Receives
`report` (the summary array) and `range`. Renders totals (views, visitors),
per-page table (views, visitors, avg scroll %, avg dwell), top referrers, and
device split, with a from/to date range form.

## Client script: `public/js/rht-analytics.js`

Vanilla, no deps, served static at `/js/rht-analytics.js`, wired site-wide via
`base.html.twig`. On load: bail if Do Not Track / GPC. Generate view id. POST
pageview. Track max scroll % (throttled) and start time. On
`visibilitychange→hidden` and `pagehide`, send engagement once via
`navigator.sendBeacon`. Also adds an optional "Share this page" + aggregate
"Read N times" bar on long-form content pages (uses `/api/page-stats`); skips
pages that ship their own share control (the records request).

## Wiring

Site-wide: a single `<script defer src="/js/rht-analytics.js"></script>` in
`base.html.twig`, so every page (including the migrated oiatc.ca redirect
landing pages) is measured.

## Infra: Caddy basic auth (waaseyaa-infra)

Add `basic_auth` for `/admin/*` in the rhtcircle site block. Password hash via
`caddy hash-password` (manual step: Russell sets the credential). Until then,
`/admin/analytics` is reachable by URL but unlinked and `noindex`.

## Differences from the OIATC original

- Renders through `App\Support\View`, not `Waaseyaa\SSR\SsrServiceProvider`.
- Collector wired once in `base.html.twig` (site-wide), not swapped per template.
- Client renamed `oiatc-analytics.js` → `rht-analytics.js`; furniture classes
  `oiatc-*` → `rht-*`; the OIATC "latest updates" explainer feed is dropped (no
  such endpoint here).
- No `chat_query_log` table or `chatGaps()` report (no chatbot on this site).
