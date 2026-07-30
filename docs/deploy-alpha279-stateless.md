# Deploy runbook: framework alpha.279 + stateless public surfaces

**Branch:** `feat/stateless-public-surfaces`
**Framework:** `0.1.0-alpha.278` → `0.1.0-alpha.279`

## THIS BRANCH IS NOT DEPLOYABLE AS-IS

`.waaseyaa/field-access-preflight.json` is **stale for this commit** and must be
regenerated before deploy, or **production will refuse to boot** with
`Field-read activation preflight is stale for the current framework or schema.`

Why: the artifact binds to `framework_version`, which the kernel computes as
`<VERSION>@<first 16 hex of sha256(composer.lock)>@<classification hash>`
(`AbstractKernel::assertFieldAccessActivationReady()`). This branch changes
`composer.lock` (69 package upgrades), so the bound value changes:

| | value |
|---|---|
| committed artifact | `dev@4af485fbd8a5cf53@classification-d735b49056f20152` |
| this commit needs | `dev@8d740a66fcc5afc2@classification-d735b49056f20152` |

The preflight runs only when `!isDevelopmentMode()`, which is why local
`APP_ENV=testing` boots fine and gives no warning. **Local success proves
nothing about this.**

### It must be regenerated against the PRODUCTION schema

Do **not** regenerate from a fresh install. Verified on 2026-07-30:

| | field entries | `media_version` columns |
|---|---|---|
| production-derived (committed) | 281 | absent — parked pre-DIR-005 shape |
| fresh local install | 289 | `blob_uri`, `created_at`, `created_by`, `media_uuid`, `mime` |

Production's `media_version` table keeps its pre-DIR-005 column shape and
nothing migrates it, so a fresh-install fingerprint
(`975afa367ab4c5ea…`) will never match the live schema
(`ba027c5a5507…`) and the artifact will not activate.

### Required pre-deploy step

On the Pi, or against a copy of the production database:

```bash
# 1. Take a backup first.
cp /srv/www/rhtcircle/storage/waaseyaa.sqlite \
   /srv/backups/rhtcircle/pre-alpha279-$(date +%Y%m%d-%H%M%S).sqlite

# 2. With THIS branch checked out and `composer install --no-dev` run
#    (so composer.lock is the deployed one), against the production DB:
WAASEYAA_DB=/srv/www/rhtcircle/storage/waaseyaa.sqlite \
  ./vendor/bin/waaseyaa field-access:preflight --write-artifact

# 3. Confirm the artifact now carries the expected framework_version and the
#    PRODUCTION fingerprint, then commit it on this branch:
python3 -c "import json; d=json.load(open('.waaseyaa/field-access-preflight.json')); \
  print(d['framework_version'], d['schema_fingerprint'], len(d['fields']))"
# expect: dev@8d740a66fcc5afc2@classification-…  ba027c5a5507…  281
```

If the fingerprint comes back as anything other than the production one, stop:
the DB you generated against is not production-shaped.

---

## What this branch changes

1. **Framework pin** `0.1.0-alpha.278` → `0.1.0-alpha.279` for
   `waaseyaa/framework`, `waaseyaa/mcp`, `waaseyaa/ai-agent` (69 packages in the
   lock). Brings:
   - **#2145** — MCP `tools/call` now enforces each tool's declared JSON Schema
     server-side before the handler runs.
   - **#2147 / #2146** — the `session.stateless_paths` mechanism this branch uses.
   - **#2154** — a `/` entry in `stateless_paths` means the root path only, which
     is what makes a cookie-free homepage expressible without breaking
     `/admin/login`.
2. **`config/waaseyaa.php`** — a `session.stateless_paths` allowlist covering the
   public surfaces. See the comment block there for why each entry is safe.

## Verification performed (2026-07-30, local, `APP_ENV=testing`)

Against a seeded SQLite database with a stable `WAASEYAA_APP_SECRET`, a
publisher bearer token, and no `WAASEYAA_DEV_FALLBACK_ACCOUNT` (a dev-fallback
account masks field-read denials and invalidates acceptance).

**Cookies — before vs after the upgrade**

Before, every anonymous GET set `PHPSESSID`, and every HTML page also set
`XSRF-TOKEN`. After:

| Path | Set-Cookie |
|---|---|
| `/`, `/news`, `/news/{slug}`, `/communities`, `/communities/sagamok`, `/communities/sagamok/accountability`, `/communities/sagamok/conflict-register`, `/sitemap.xml`, `/llms.txt`, `/about`, `/safety`, `/land`, `/treaty`, `/resources`, `/standard/records-request`, `/contact`, `/updates` | none |
| `/admin` | `PHPSESSID`, `XSRF-TOKEN` |
| `/admin/login` | `PHPSESSID`, `XSRF-TOKEN` |
| `/api/page-stats` | `PHPSESSID` |

Guards confirmed live:
- a request carrying `PHPSESSID` on a stateless path resumes a session;
- `HEAD /` is cookie-free (HEAD is in scope);
- `/newsletter` is not matched by the `/news` prefix (404, no prefix bleed).

**Admin authentication** — a real end-to-end login: `GET /admin/login` mints
session + CSRF and renders a `_csrf_token` field; `POST` with credentials
returns `302 → /admin/anokii`; the session then loads
`/admin/anokii/analytics` with `200`, while the same URL anonymously redirects
to `/admin/login?next=…`. `/admin` itself is the Nuxt SPA shell and is
byte-identical for both, which is expected — it authenticates client-side
against the API.

**MCP publishing acceptance** — 14/14, up from 11/14 before the upgrade. The
three newly-passing checks are the #2145 behaviours:

| | alpha.278 | alpha.279 |
|---|---|---|
| `article.rollback` without `target_revision_id` | PHP `Undefined array key` warning HTML prefixed onto the JSON body, then `"Revision 0 does not exist for entity 1."` | clean `{"code":"VALIDATION_FAILED","errors":[{"field":"target_revision_id","message":"This argument is required."}]}` |
| unexpected argument | accepted, reached handler | `VALIDATION_FAILED` |
| warnings in the server log | present | zero |

Also covered: unauthenticated `/mcp/write` → 401; public `/mcp` still hides
every `article.*` write tool; createDraft → publish → rollback → unpublish all
succeed; an idempotent replay returns the original entity id and revision; a
signed preview URL renders 200; an unpublished article 404s.

**Editorial correctness** — publishing a `whitefish-river` article makes it
appear on `/news` (6 → 7 links) and in the sitemap, and **not** on the
Sagamok-filtered hub; publishing a `sagamok` article does appear there. So
community/bundle filtering is intact in both directions. Unpublishing returns
`/news` to 6.

**Public form submissions** still work with their pages now cookie-free
(`fetch()` posts `application/json`, which `CsrfMiddleware` exempts by content
type, and no script reads the XSRF cookie): `POST /api/contact` → `{"ok":true}`;
`/api/poll/vote` and `/api/signup` return application-level validation
responses, not CSRF 403s.

**Anonymous crawl** — 87 paths reached from `/` plus the sitemap:
**0 5xx, 0 4xx, 0 responses setting cookies.**

**App test suite** — 32 tests, 340 assertions, green.

### Not verified, and why

**Pagination.** This site has 6 published articles against a page size of 20,
renders no pagination control, and ignores `?page=` (all variants return 200,
none 5xx). Pagination is supplied by the framework Listing pipeline but is not
exercised by this app, so there is nothing here for this change to have broken.
Stated rather than claimed.

## Deploy order

1. Regenerate the preflight artifact against the production schema (above) and
   commit it.
2. Merge to `main`.
3. Pin the infrastructure repo to the new rhtcircle SHA. **Verify
   `git ls-remote origin main` shows the new SHA before deploying** — a previous
   round lost a pin commit to an off-branch push and silently deployed stale
   source.
4. Deploy inside a maintenance window. No migrations are required by this
   change.
5. Post-deploy: re-run the cookie table above against the live host, confirm
   `/admin/login` still sets `PHPSESSID` + `XSRF-TOKEN`, and re-run the MCP
   acceptance with the production publisher token.
