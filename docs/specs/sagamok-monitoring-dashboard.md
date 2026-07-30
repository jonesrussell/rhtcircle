# Sagamok monitoring dashboard — implementation specification

**Status:** implementation-ready. Documentation review only; **no collector code
has been written.**
**Audience:** whoever implements this in `jonesrussell/rhtcircle`.
**Framework baseline:** `waaseyaa/framework` 0.1.0-alpha.279 or newer.

---

## 0. Scope

One monitor, of the Nation's **genuinely public website**. It answers a question
members currently cannot answer: *did the Nation publish something new, and did
something published quietly change or disappear?*

The members-only portal is **properly access-controlled**, and this feature does
not monitor it. There is no collector for it, no archive querying, no
authentication, and no indexing. What the dashboard carries about the portal is a
**status statement** and a record of manual, member-supplied notes — §7.

### Non-goals

- Not a document library. It republishes no protected content.
- Not an accusation engine. Per the app's standing guardrails (`CLAUDE.md` rules
  1 to 4) it presents sourced, dated facts and the questions members are entitled
  to ask, and it names offices and roles, never private individuals.
- Not a login client, and not an archive client. It has no credential store, no
  code path that authenticates to any Sagamok system, and no code path that
  queries a web archive or search index.

---

## 1. What this delivers

This is the whole feature. An earlier draft split it into a public-site "Part A"
and a portal-archive "Part B"; Part B has been **removed** rather than deferred,
and §7 replaces it with a status statement. Where "Part A" still appears below it
means this, the only, implementation target.

| Requirement | Where |
|---|---|
| Monitor Sagamok's genuinely public website | §4 |
| Track **new**, **changed**, **disappeared**, **reappeared** pages and documents | §4.3 |
| Source health and last-check timestamps | §3.1 |
| Current issues, and related **official public** updates | §3.4, §3.5 |
| CMS entities and Waaseyaa listings | §3, §5 |
| No hardcoded Twig datasets | §2 |

---

## 2. Conformance with existing app patterns

**Follow:** collections resolve through the framework Listing pipeline
(`HasListingsInterface::listings()` → `ListingDefinition` → `ListingResolver`),
and templates receive flat arrays produced by a repository `view()` method. The
reference implementation is `src/Cms/ArticleRepository.php`.

**Do not repeat:**
`templates/pages/communities/sagamok/conflict-register.html.twig` is 1064 lines,
most of it a `var DATA = {…}` JavaScript object literal holding rosters and vote
records. It has no PHP model, no entity, no test, and no way to query it. **Every
fact this dashboard shows is an entity row reached through a Listing.** No page
in this feature may contain a data literal, in PHP or in Twig.

Two framework constraints that shape the field design:

- **Rule G:** a field used in a Listing `filters` or `sorts` must be
  `FieldStorage::Column`. `_data`-blob fields raise `UnsupportedListingException`
  at boot (`ListingDefinitionValidator`). Note the validator enforces *Column*
  only — `->indexed()` is a performance requirement, not a framework-enforced
  one, so an unindexed facet will validate and then scan. Index them anyway, and
  decide the facet set before writing field definitions.
- **Preflight:** adding entity-storage columns changes the field-access schema
  fingerprint, so `.waaseyaa/field-access-preflight.json` must be regenerated
  **from the production schema** (a `VACUUM INTO` snapshot, or a DDL-only export,
  of the live database) and never from a fresh install. Since #2143 the
  fingerprint covers **registered entity-storage tables only** — each type's base
  table plus its `<type>__*` subtables — so lazily-materialised non-entity tables
  no longer stale it; but new monitor columns will, and the preflight runs only
  outside development mode, so a local `APP_ENV=testing` boot proves nothing
  about it. See `docs/deploy-alpha279-stateless.md` for the rehearsal procedure.

Listing ids are globally unique across providers or boot fails hard, and bundle
fields must be registered before Listing validation runs.

---

## 3. Entity model

Six entity types, registered in a new `SagamokMonitorServiceProvider`
(`src/Provider/SagamokMonitorServiceProvider.php`) declared in `composer.json`
`extra.waaseyaa.providers` **after** `CmsContentServiceProvider`. Each is an
attribute-driven `ContentEntityBase` subclass in `src/Entity/`, following
`MythEntry`'s shape.

### 3.0 Three rules that govern every table below

**A field's read level is a property of the field definition, not of the row.**
`FieldReadLevel` is declared once on the definition and applies to every row of
that type. There is therefore **no field anywhere in this model whose level
varies by source, kind, or any other row value**. An earlier draft wrote things
like "Public for `public_site`, Protected for `portal_index`"; that is not
expressible and was a design error. Where two things need different levels, they
are **different entity types** — which is why the portal status in §7 is its own
type rather than a `kind` flag on `monitor_source`.

**No publicly-rendered field carries an internal identifier.** `item_key`, row
ids, and hashes never appear in a public projection, and events and issues relate
to items **only** by `public_ref` — an opaque per-item counter (§3.2). This is
the correction for the composition leak recorded in §6.0.

**Internal means Internal.** A value that must not be public is declared
`#[Field(read: FieldReadLevel::Internal)]`, not merely omitted from a template. In
this app that is a hard guarantee: an `Internal` read throws unconditionally
without an audited capability, and no registered policy supplies a
`protectedFieldReadPolicy()` for these types, so a `Protected` read would throw
for every caller too. The field-read guard is installed at boot **independently**
of the production preflight, so these denials are live under `APP_ENV=testing` and
are therefore testable. The sanctioned reader for Internal values is §6.4.

**This model declares zero `Protected` fields, deliberately.** Everything is
either Public or Internal. The reason is a real asymmetry:
`EntityValues::toCastAwareMap()` filters Internal fields out of its map, so a
serialization path skips them harmlessly — but a **Protected** field is not
filtered and would *throw* on such a path, turning a locked-down field into a 500
on some future surface. If anyone adds a Protected field here, that safety
property is gone and §9.2 must be re-examined.

### 3.1 `monitor_source` — what we watch, and whether the watching is healthy

Public-website sources only. There is no portal source and no `kind` field.

| Field | Storage | Read level | Notes |
|---|---|---|---|
| `key` | Column, indexed | Public | Stable slug, e.g. `sagamok_public_site` |
| `label` | Column | Public | "Sagamok public website" |
| `origin_url` | Column | Public | A public website URL. Public because the site is public |
| `enabled` | Column, indexed | Public | Operator switch |
| `health` | Column, indexed | Public | `ok` \| `degraded` \| `failing` |
| `last_check_started` | Column, indexed | Public | Unix seconds |
| `last_check_completed` | Column, indexed | Public | Unix seconds. **Both** are stored so a run that never finished shows as a stall rather than reporting stale data as fresh |
| `last_success` | Column, indexed | Public | Last check that completed without error |
| `consecutive_failures` | Column | Public | Drives `health` |
| `last_error` | Data | **Internal** | Diagnostic text may quote a response fragment |

**Source health is defined, not implied:**

- `ok` — last check completed and `consecutive_failures == 0`.
- `degraded` — 1 or 2 consecutive failures, **or** `last_success` older than 2×
  the expected interval.
- `failing` — 3 or more consecutive failures, **or** `last_success` older than
  7 days.

A source whose `last_check_completed` trails `last_check_started` by more than one
interval is reported as a **stalled run**, not as healthy.

### 3.2 `monitor_item` — a public page or document tracked over time

| Field | Storage | Read level | Notes |
|---|---|---|---|
| `source_key` | Column, indexed | Public | FK to `monitor_source.key` |
| `public_ref` | Column, indexed | Public | **The only identifier that is ever rendered or related to.** An opaque per-source counter (`p-1`, `p-2`, …) assigned at first sight. Unique with `source_key`. Carries no preimage |
| `title` | Column | Public | The public page's own title. Public because the page is public |
| `public_url` | Column | Public | Same reasoning |
| `doc_kind` | Column, indexed | Public | `page` \| `document` \| `unknown` |
| `change_status` | Column, indexed | Public | `new` \| `unchanged` \| `changed` \| `disappeared` \| `reappeared` |
| `first_seen` | Column, indexed | Public | Unix seconds |
| `last_seen` | Column, indexed | Public | Unix seconds |
| `changed_at` | Column, indexed | Public | Last content change |
| `disappeared_at` | Column, indexed | Public | Set when a previously-present item stops appearing |
| `event_count` | Column | Public | Denormalized, for display |

**`item_key` is deliberately absent from this table.** The collector's stable
identity key, the content hash, and the normalized snapshot all live in the
side table (§3.6), so there is no internal identifier on the entity at all and
nothing for a projection to leak by accident.

`change_status` is a **derived projection** of the newest `monitor_event`, stored
as a column because it is a primary listing facet (Rule G). It **must** be
written in the same save as the event that causes it. A projection that can
disagree with its own event log is the dual-state bug this codebase has been
bitten by before.

### 3.3 `monitor_event` — the append-only change log

One row per observed transition. Never updated, never deleted, except through the
operator redaction in §3.7.

| Field | Storage | Read level | Notes |
|---|---|---|---|
| `source_key` | Column, indexed | Public | |
| `item_public_ref` | Column, indexed | Public | Relates to `monitor_item.public_ref`. **Never `item_key`, never a row id** |
| `event_type` | Column, indexed | Public | `appeared` \| `content_changed` \| `metadata_changed` \| `disappeared` \| `reappeared` \| `became_gated` |
| `observed_at` | Column, indexed | Public | Unix seconds. Observation time |
| `effective_at` | Column, indexed | Public | The date the source itself claims, when it publishes one. Nullable |
| `evidence_kind` | Column, indexed | Public | `direct_fetch` — the only permitted value. There is no archive or search-index provenance in this feature |
| `evidence_url` | Column | Public | The public URL fetched. Public because it is public |
| `evidence_captured_at` | Column | Public | When the fetch happened |
| `notes` | Data | **Internal** | Operator diagnostics |

Hash pairs are **not** stored on the event. A change is evidenced by the event
existing plus the side-table history; publishing hashes buys a reader nothing here
and the habit is worth not forming.

### 3.4 `monitor_issue` — the current-issues tracker

The editorial layer: a member-facing issue, hand-authored, linked to the
machine-observed record and to official public updates.

| Field | Storage | Read level | Notes |
|---|---|---|---|
| `slug` | Column, indexed | Public | |
| `title` | Column | Public | Framed as a question or a neutral statement of what is outstanding |
| `status` | Column, indexed | Public | `open` \| `awaiting_response` \| `partly_answered` \| `resolved` \| `withdrawn` |
| `opened_at` | Column, indexed | Public | |
| `status_changed_at` | Column, indexed | Public | |
| `closed_at` | Column, indexed | Public | Required when `status = resolved` |
| `summary` | Data | Public | Short member-facing text |
| `what_is_asked` | Data | Public | The specific ask |
| `related_article_slugs` | Data | Public | Links to `node`/`article` records by slug |
| `related_item_public_refs` | Data | Public | **Opaque refs only** (§3.0) |
| `severity` | Column | **Internal** | `information` \| `concern` \| `urgent`. Editorial triage state. Internal, and therefore unreadable without the §6.4 audited path — not merely unrendered |

`severity` is **Internal and is not a listing facet.** It orders the maintainer's
own CLI report (§6.4), never a public list. A public list sorted by an editorial
severity, with a public `opened_at` beside it, composes into an automatically
escalating countdown clock against an office — generated forever, with no author
taking responsibility for the claim. The prose form of that argument already
exists on `/communities/sagamok/it-accountability`, written and dated by a named
member. Render `opened_at` and let the reader do the arithmetic; do not render a
computed "days overdue" figure.

A `monitor_issue` is never auto-created. Machine observation proposes; a member
decides.

### 3.5 `monitor_official_update` — what the Nation actually said, publicly

| Field | Storage | Read level | Notes |
|---|---|---|---|
| `issue_slug` | Column, indexed | Public | FK to `monitor_issue.slug` |
| `published_at` | Column, indexed | Public | |
| `source_label` | Column | Public | "Council minutes, June 3 2026" |
| `source_url` | Column | Public | A **public** URL only. A source that is not public is recorded by label with an empty URL |
| `summary` | Data | Public | Neutral description |
| `answers_ask` | Column | **Internal** | `yes` \| `partly` \| `no` \| `unclear`. Editorial judgement. Internal, not merely unrendered |

`answers_ask` is **Internal and is not a listing facet.** A machine-readable,
sortable public scorecard of `no` against the Nation's official statements is a
conclusion about conduct, not a sourced fact framed as a question, and it breaks
hard rule 2. (The 2026-07-15 exception is scoped explicitly to
`/communities/sagamok/member-accountability-resolution`; a new route gets rules
1 to 4 in full.) Render the **ask and the official update side by side** and let
the member judge. That is what rule 2 is for.

### 3.6 The collector side table — hashes, keys, snapshots

**This is a decision, not an option.** Content hashes, the collector's stable
identity key, and normalized page snapshots live in a **non-entity side table**
owned by the collector, reached through `DatabaseInterface` directly. The app's
own rules permit `DatabaseInterface` for supporting tables: this table has no
independent lifecycle, is never rendered, and is never related to.

```
monitor_collector_state
  source_key       TEXT NOT NULL
  item_key         TEXT NOT NULL   -- sha256(normalized_url); the collector's identity
  item_public_ref  TEXT NOT NULL   -- the opaque ref exposed on the entity
  content_hash     TEXT NOT NULL
  normalized_bytes INTEGER
  snapshot         BLOB            -- capped; pruned past the retention window
  updated_at       INTEGER NOT NULL
  PRIMARY KEY (source_key, item_key)
```

Why a side table rather than `Internal` entity fields:

- An `Internal` field cannot be read without an audited capability, so the
  collector's own hash comparison (§4.3 step 3) **could not execute** against it.
  An earlier draft specified exactly that and would have been discovered broken
  mid-implementation, at which point the likely fix is
  `EntityReadRuntime::installGuard(null)` — a public static that disables the
  guard process-wide and re-opens every field in the app.
- Values that never enter an entity cannot leak through an entity projection,
  through a Listing facet, or through any auto-generated route. The guarantee is
  structural rather than enforced.
- `item_key` → `item_public_ref` mapping lives here, so the opaque ref has
  somewhere to be resolved without exposing the key.

The side table is **not** an entity, so it has no `FieldReadLevel`, appears in no
listing, and is unreachable through MCP, GraphQL, JSON:API, SSR, or Discovery.

### 3.7 Correction and erasure

`monitor_event` is append-only by default, and that is right. It is not an
absolute: the existing disclosure pages invite corrections, and a member may
legitimately ask for a row to be removed.

Define an **operator redaction**: a `redacted_at` timestamp plus a
`redaction_reason` (Column, Public — a fixed enum label, not free text) that
suppresses the row from every projection while retaining a stub, so the log does
not silently develop holes. Redaction is a CLI action with a recorded reason, and
it is the only write that ever touches an existing event.

---

## 4. Collection

### 4.1 Scheduled entry point

This app has no scheduled tasks today; this adds the first.

- `src/Schedule/SagamokMonitorSchedule.php` implements `ScheduleEntriesInterface`,
  marked `@api`, declaring `register(ScheduleInterface $schedule): array`.
- One task, `sagamok:monitor-public`, hourly.
- One CLI command, `sagamok:monitor-public`, supporting `--dry-run`, so a run is
  reproducible by hand.
- Verify discovery with `bin/waaseyaa schedule:list`.

Runs must be **idempotent**: two runs over unchanged upstream state produce zero
new `monitor_event` rows. This is the single most important behavioural property
and it has its own test (§9).

### 4.2 Identity and duplicate detection

`item_key` (side table only) is `sha256(normalized_url)`, where normalization
lowercases the host, drops the fragment, strips known tracking parameters, and
collapses a trailing slash. That is what makes `?utm_source=…` variants one item
rather than many. Duplicate detection is therefore **structural** — the primary
key does the work.

On top of that, a **near-duplicate guard**: when a new `item_key` appears in the
same run as a `disappeared` item on the same source with an identical
`content_hash`, record `reappeared` (a move) against the existing item rather than
minting a new one. Without this, one upstream URL rename produces a spurious
"document disappeared" plus a spurious "new document" — the kind of false alarm
that would discredit the dashboard.

### 4.3 Update detection

For each item observed in a run:

1. **Re-gating gate first** (below). If the page is no longer genuinely public,
   record `became_gated` and store nothing.
2. Compute `content_hash` over the **normalized** content.
3. No existing row → `appeared`, `change_status = new`.
4. Hash differs → `content_changed`, `change_status = changed`, `changed_at` set.
5. Hash equal, tracked metadata differs → `metadata_changed`, `change_status`
   unchanged.
6. Hash equal, metadata equal → **no event**; refresh `last_seen` only.
7. Previously present, absent this run → `disappeared`, `disappeared_at` set.
   Requires **two consecutive** absent runs, so a single upstream timeout is not
   reported as a removal.
8. Absent then present again → `reappeared`, `disappeared_at` cleared.

**Re-gating gate.** Before hashing or storing anything, the collector checks that
the page is still genuinely public, and **skips and reports** rather than storing
if it is not:

- HTTP 401/403, or a redirect to a login path → `became_gated`, nothing stored.
- HTTP 200 whose body is a login shell, or which carries `noindex` →
  `became_gated`, nothing stored.

This matters because the portal's original defect *was* a 200 response with a
client-side login overlay. Without this branch, a page moved behind the portal
would be fetched at 200, hashed, and snapshotted — turning a public-website
monitor into a copy of protected material. A `became_gated` observation is a
**finding to report**, not content to collect.

**Content normalization** before hashing strips what changes on every fetch
without the content changing: session ids, CSRF tokens, cache-buster query
strings, footer timestamps, and rotating asset hashes. Un-normalized hashing
yields "everything changed, every hour", which is the same as no signal. The
normalizer is pure and unit-tested (§9).

---

## 5. Listings

Registered by `SagamokMonitorServiceProvider` implementing `HasListingsInterface`.
Ids are prefixed `sagamok_monitor_` for global uniqueness. Every field named in
`filters`/`sorts` is `FieldStorage::Column` + `->indexed()` per Rule G, **and is
Public** per §6.3.

| Listing id | Entity | Filters | Sorts | Page size | Serves |
|---|---|---|---|---|---|
| `sagamok_monitor_sources` | `monitor_source` | `enabled = true` | `key` asc | 25 | Source-health strip |
| `sagamok_monitor_items` | `monitor_item` | `source_key = sagamok_public_site` | `last_seen` desc | 25 | Tracked-pages table |
| `sagamok_monitor_changes` | `monitor_item` | `change_status IN (new, changed, disappeared, reappeared)` | `changed_at` desc | 25 | "What changed" |
| `sagamok_monitor_timeline` | `monitor_event` | `redacted_at IS NULL` | `observed_at` desc | 50 | Change timeline |
| `sagamok_monitor_issues_open` | `monitor_issue` | `status IN (open, awaiting_response, partly_answered)` | `opened_at` desc | 25 | Current-issues tracker |
| `sagamok_monitor_issues_resolved` | `monitor_issue` | `status = resolved` | `closed_at` desc | 25 | Resolved archive |
| `sagamok_monitor_updates` | `monitor_official_update` | none | `published_at` desc | 25 | Official-updates rail |

Every definition sets **`accessOps: ['monitor.dashboard_read']`** — see §6.2. No
listing sorts by `severity` or `answers_ask` (both Internal). No listing filter is
driven by a request parameter.

`SagamokMonitorRepository` mirrors `ArticleRepository`: resolve a definition, map
each entity through `view()`, hand flat arrays to Twig. Pagination comes from the
Listing pipeline; templates never slice arrays.

**`view()` is the single redaction boundary.** It reads only the Public fields
enumerated in §3 and structurally cannot emit an Internal one. Redaction is one
auditable method, not a rule spread across templates.

---

## 6. Exposure boundaries

### 6.0 The failure mode this section exists to prevent

An earlier draft of this document assigned read levels field by field, reasoned
carefully about each one, and still leaked. The leak was not in any single field.
It was in the **composition of fields that were each individually harmless and
Public by design**: an `item_key` that was a hash over a publicly enumerable
preimage; capture timestamps that were an archive's primary key; a category label
plus a date that together identified what a document was; and a per-item count
that made the tracked set enumerable.

None of it would have been caught by a sentinel-based redaction test, because
every one of those fields was Public **on purpose**.

**Two rules follow.**

1. **Reason about the public projection as a whole, not field by field.** §6.3 is
   a closed list, and §9 asserts it as a set equality.
2. **The threat model is a reader who wants the protected thing**, not a careless
   reader. Every safeguard below is written against that adversary.

The present document is materially less exposed than the draft that failed,
because the archive collector is gone: there is no protected-material index left
to leak. Part A's dataset is metadata about pages that are already public. The
rules above still apply, because §7 and future additions will be tempted.

### 6.1 What the framework auto-exposes, verified

Registering an entity type does **not** create JSON:API routes — `api` defaults to
`false` on both `EntityType` and `#[ContentEntityType]`, and
`EntityType::fromClass()` has no `$api` parameter at all, so the only way to turn
JSON:API on is writing `api: true` in the attribute. **Do not write it.**

But four surfaces **are** generic over any registered entity type and need no
opt-in. For all four the only gate is the access policy:

| Surface | Reachability | Gate |
|---|---|---|
| MCP `entity.read`, `entity.search` | `/mcp` is `allowAll()`; anonymous callers hold `tool.entity.read` and `tool.entity.search` | entity access + field read levels |
| GraphQL | `/graphql` is `allowAll()`; the schema is generated for **every** registered type, with no per-type opt-out | entity access via `GraphQlAccessGuard::canView()` |
| `/api/discovery/{hub,cluster,timeline,endpoint}/{entity_type}/{id}` | `allowAll()`, generic over `{entity_type}` | published status **and** entity access |
| SSR `GET /{entity_type}/{id}` | the catch-all `/{path}` route resolves any two-segment `type/id` | **only for `group: 'content'` types** |

**Two mechanisms that look like protection and are not:**

- `discoverable: false` has exactly one consumer — whether the type appears as a
  link in the `GET /api` discovery document. It gates no CRUD route, no access
  check, no MCP tool, no GraphQL field. Its own docblock says so.
- The entity-type **disable** mechanism (`POST /api/entity-types/{entity_type}/disable`,
  persisted to `storage/framework/entity-type-status.json`) is consulted by
  **zero read paths**. Disabling a type does not make it unreadable.

Neither may be cited as a boundary in review.

### 6.2 How automatic exposure is prevented

`EntityAccessHandler::check()` starts Neutral and entity-level access uses
`isAllowed()`, so **Neutral is a denial**. With no policy granting `view`, a
registered type is denied on every surface in §6.1. That is the safe default and
this feature keeps it.

The complication is that the Listing pipeline applies a per-row gate and
`ListingDefinition` **rejects an empty `accessOps`** (`$accessOps must be
non-empty`), so a listing cannot opt out of the check. A type that denies `view`
to everyone would therefore return no listing rows either.

The resolution: `GateInterface::allows(string $ability, …)` takes a **free-form
ability string**, and `accessOps` entries are validated only as non-empty strings.
So:

- Register **one** `AccessPolicyInterface` for the six monitor types that grants
  exactly one bespoke ability, **`monitor.dashboard_read`**, and returns
  `neutral()` for every other operation — including `view`.
- Every `ListingDefinition` in §5 sets `accessOps: ['monitor.dashboard_read']`.

The result, which §9 asserts route by route:

| Path | Outcome |
|---|---|
| The dashboard's own listings | resolve — they ask for `monitor.dashboard_read` |
| MCP `entity.read` / `entity.search` | check `view` → Neutral → **denied** |
| GraphQL | checks `view` → **denied** |
| `/api/discovery/*` | checks `view` → **denied** |
| SSR `GET /{type}/{id}` | not applicable: **no monitor type uses `group: 'content'`** |
| JSON:API `/api/{type}` | route absent: `api` stays `false` |

**The four rules that make this hold, all of which are omissions or prohibitions
rather than configuration:**

1. **Never set `api: true`** on any monitor `#[ContentEntityType]`.
2. **Never set `group: 'content'`.** This is load-bearing and is the easiest
   mistake to make, because the app's existing types use it. `group: 'content'`
   is an *affirmative grant of anonymous read*: the kernel unconditionally
   registers `PublishedContentAccessPolicy`, which allows `view` for any
   `content`-group entity whose `status` is 1, simultaneously opening MCP, SSR,
   GraphQL, and Discovery. Use `null`.
3. **No monitor type has a `status` field**, so even a future policy change
   cannot make `WorkflowVisibility::isEntityPublic()` true and open the Discovery
   routes.
4. **The policy grants `monitor.dashboard_read` only** and must not grant `view`.
   It should also not declare `SUPPORTS_LISTING_FAST_PATH`, though note that
   prohibition is belt-and-braces rather than load-bearing:
   `ListingResolver::canUseAccessFastPath()` returns false whenever `accessOps`
   differs from the default `['view']`, *before* it probes the constant, so on
   these listings the fast path is structurally unreachable and the constant is
   inert. Keep the assertion anyway — it costs nothing and documents intent.

Note one accepted cost: because `accessOps` differs from the default `['view']`,
the Listing pipeline adds a `user.roles` cache context, so these listings vary by
role and cache slightly less well. That is correct behaviour and the volume is
small.

### 6.3 The public projection, exhaustively

A **closed list**. `view()` emits exactly these keys and nothing else. Adding one
requires redoing the §6.0 composition analysis.

- **Source:** `key`, `label`, `origin_url`, `health`, `last_check_completed`,
  `last_success`, and a derived `stalled` boolean.
- **Item:** `public_ref`, `title`, `public_url`, `doc_kind`, `change_status`,
  `first_seen`, `last_seen`, `changed_at`, `disappeared_at`, `event_count`.
- **Event:** `item_public_ref`, `event_type`, `observed_at`, `effective_at`,
  `evidence_kind`, `evidence_url`, `evidence_captured_at`.
- **Issue:** `slug`, `title`, `status`, `opened_at`, `status_changed_at`,
  `closed_at`, `summary`, `what_is_asked`, `related_article_slugs`,
  `related_item_public_refs`.
- **Official update:** `issue_slug`, `published_at`, `source_label`,
  `source_url`, `summary`.
- **Portal status (§7):** `state`, `last_verified_on` (month precision),
  `statement`.

**Never in any projection:** `severity`, `answers_ask`, `last_error`, `notes`,
`item_key`, `content_hash`, `normalized_bytes`, `snapshot`, any entity row id, and
anything from the §3.6 side table.

### 6.4 The sanctioned reader for Internal values

`severity`, `answers_ask`, `last_error`, `notes`, `method_note`, and `review_note`
are Internal, so they throw without an audited capability. The sanctioned reader
is a **maintainer CLI report** — `sagamok:monitor-triage` — reading them through
`Waaseyaa\Audit\AuditedFieldRead` under a capability from
`Waaseyaa\CLI\Security\CliFieldReadCapabilityIssuer`: CLI-only, TTL-bounded,
reason-scoped, and ledgered.

The issuer does not mint a capability from a field name directly. The report must
build a `CliFieldReadCapabilityDeclaration`, `register()` it, then `issue()` with a
`CapabilityExecutionBoundary` and an `expiresAt`. `AuditedFieldRead::readMany()`
reserves a strict ledger entry per read, rejects an empty or duplicated field
list, and can require a specific `CapabilityReason` — so budget for a little more
wiring than a plain getter.

Do **not** register a permissive `ProtectedReadPolicyProviderInterface` and do
**not** call `EntityReadRuntime::installGuard(null)`. Either would re-open every
field in the app, not just the one being read.

Read levels govern the accessor only. These values sit in plaintext columns in a
SQLite file on a host shared with other sites: set file permissions deliberately,
and exclude monitor tables from any dump that leaves the host.

---

## 7. Portal monitoring status (replaces the former Part B)

The members-only portal is **properly access-controlled**. There is therefore
nothing for this feature to monitor, and it does not try.

**Prohibited, with no configuration that could enable them:** authenticating to
the portal; replaying or reproducing any bypass; querying a web archive or a
search index; enumerating portal items; storing or rendering a locator, a hash, an
archive timestamp, a signature or signed URL, a direct file link, a raw document,
a password, or member PII. There is no collector, no scheduled task, no HTTP
client, and no credential configuration for the portal. A code-review checklist
item: the monitor packages contain no `Authorization` header construction, no
`password`, and no archive or search-index host.

### 7.1 `portal_access_state` — the status statement

A small append-only record of **manual, independent** verifications. Nothing
automated ever writes it.

| Field | Storage | Read level | Notes |
|---|---|---|---|
| `verified_on` | Column, indexed | Public | The date of the manual check. **Month precision when rendered** (§6.3) |
| `state` | Column, indexed | Public | `access_controlled` \| `not_access_controlled` \| `unknown` |
| `statement` | Data | Public | Member-facing prose, subject to rules 1 to 4 |
| `verified_by_role` | Column | Public | A role, never a person (rule 3) |
| `method_note` | Data | **Internal** | How it was checked. Internal so that no description of a checking technique is ever public |

The dashboard renders the newest row as:

> **Monitoring unavailable: the members-only portal is properly access-controlled.**
> Last independently verified: `<month year>`. No automated monitoring of the
> portal is performed.

Two things that statement deliberately does **not** claim: that any historical
exposure has been remediated, and that anything is or is not present anywhere.
It reports only the access state that was independently verified, and the date.
Publishing an unverified all-clear about members' data would be the specific act
the existing disclosure pages hold the Nation accountable for; this feature must
not repeat it with better instrumentation.

If a verification ever records `not_access_controlled`, that is a **finding to
report through the existing disclosure channels**, not a trigger to begin
collecting. Nothing in this feature starts monitoring in that case.

### 7.2 `portal_update_note` — member-supplied official update metadata

Manually reviewed metadata about updates published **inside** the portal may be
recorded — **only** when a member deliberately supplies it. This is the one path
by which portal-adjacent information enters the app, and it is a human one.

| Field | Storage | Read level | Notes |
|---|---|---|---|
| `supplied_on` | Column, indexed | Public | Month precision when rendered |
| `official_date` | Column, indexed | Public | The date the update itself carries, if any |
| `title_supplied` | Column | Public | **As a member chose to describe it**, reviewed before publication. Never a filename, never copied from a document |
| `summary` | Data | Public | Neutral, member-safe description |
| `supplied_by_role` | Column | Public | A role, never a person |
| `review_note` | Data | **Internal** | The reviewer's own notes |

**Hard constraints, enforced at ingest rather than at render:**

- No URL field exists, so there is no direct file link and nothing to strip.
- No attachment, blob, body, or excerpt field exists, so no raw document can be
  stored.
- No hash and no identifier field exists, so nothing composes into a confirmation
  oracle or an enumeration.
- A CLI/admin entry path only. There is **no public submission form** — a member
  supplies this to a maintainer out of band, and a maintainer reviews it against
  rules 1 to 4 before it is entered.
- The reviewer is responsible for confirming no member PII, no signature, and no
  password appears in any supplied field. §9 asserts the shape; only a human can
  assert the content.

Ordinary `monitor_official_update` (§3.5) remains the record for **public**
official updates and requires a public URL. The two are separate because their
provenance rules differ, and conflating them would let a portal-sourced item
inherit the public one's assumptions.

### 7.3 What is out of scope for this document

Whether to file an archive exclusion request, whether and how to notify Chief and
Council, and any terms-of-service determination are **external decisions**, not
software requirements. They are deliberately not preconditions here. This
specification's obligation is narrower and absolute: the software must not
authenticate to the portal, must not bypass it, must not systematically index it,
and must not expose protected material. That obligation is met by the absence of
the code, not by a process gate.

---

## 8. Routes and rendering

| Route | Path | Renders |
|---|---|---|
| `sagamok-monitor` | `/communities/sagamok/monitor` | Source health, portal status, current issues, change timeline |
| `sagamok-monitor-issue` | `/communities/sagamok/monitor/{slug}` | One issue: the ask, status history, related articles, official updates |

Both `->allowAll()`, GET only, following the app's route shape (a
`SiteController` method plus a route row). Both are already covered by the
`/communities` prefix in `config/waaseyaa.php` `session.stateless_paths`, so they
are cookie-free for anonymous readers.

Templates extend `base.html.twig`, receive **flat arrays only**, and contain no
data literals. Every rendered row shows what the dashboard promises: **when
something appeared, changed, or disappeared**, its **change status**, the
**source's health and last-check time**, and the **evidence kind and capture
date**.

A visible per-source "last checked" and health badge is **required, not
optional**. A monitoring dashboard that silently stops checking is worse than no
dashboard, because it invites members to read a stale page as current.

**No JSON or API route is added by this feature.** If one is ever wanted it must
resolve through `SagamokMonitorRepository::view()` — the same projection as the
HTML path — and it inherits every §9 assertion. A route that serializes entities
directly is prohibited.

---

## 9. Tests

App conventions: two suites (`Unit`, `Integration`), plain `TestCase`, real kernel
over a temp SQLite file initialised by shelling out to `vendor/bin/waaseyaa
db:init`, `APP_ENV=testing`, `APP_DEBUG=false`, and **no**
`WAASEYAA_DEV_FALLBACK_ACCOUNT` — a dev-fallback account masks field-read denials
and invalidates the result. Expectations derive from fixtures, never hardcoded
counts.

### 9.1 Composition, against the complete public projection

This is the test that would have caught the §6.0 leak, and it is the most
important one here.

`tests/Integration/SagamokMonitor/PublicProjectionTest.php`

- For **each** of the six entity types, the projection emitted by `view()` is
  asserted as a **set equality** against the §6.3 closed list. Adding a key
  anywhere fails until someone revisits §6.0.
- Seed every Internal field (`severity`, `answers_ask`, `last_error`, `notes`,
  `method_note`, `review_note`) and every side-table value (`item_key`,
  `content_hash`, `snapshot`) with distinctive sentinels, then assert **no
  sentinel appears anywhere** in either route's response body.
- Assert no entity row id appears in any response.
- Assert every relation in a response resolves via `public_ref` /
  `item_public_ref` / `slug` and never via `item_key` or a row id — checked on
  the **timeline** and on **issue relations** specifically, which are the two
  places a relation is rendered.
- Assert `public_ref` values are opaque: they match `^p-\d+$` and are not derived
  from any other stored value.
- Assert dates rendered for portal rows are month-precision only.
- **No `ListingDefinition` names an Internal field in `filters` or `sorts`** —
  read from the registry and cross-referenced against the field definitions. The
  framework does not enforce this.
- No listing filter is driven by a request parameter.

### 9.2 Auto-exposure lockdown, route by route

`tests/Integration/SagamokMonitor/NoAutoExposureTest.php`

For every monitor entity type, with a seeded row, anonymously:

- MCP `tools/call` `entity.read` and `entity.search` against the type → refused,
  and the response contains **no** field value from the row. Assert on the
  *absence of data*, **not** on the word "denied" or "forbidden":
  `EntityReadTool` deliberately collapses view-forbidden and absent into a
  byte-identical `entity.read: {type}/{id} not found`, so a locked-down type is
  indistinguishable from a non-existent one. A test expecting a distinguishable
  refusal will fail on a correctly-locked-down type — and that
  indistinguishability is the desired property, since it closes the
  existence-oracle.
- `/graphql` query for the type → refused or absent.
- `/api/discovery/{hub,cluster,timeline,endpoint}/{type}/{id}` → refused.
- `/api/{type}` → **404** (route absent).
- `GET /{type}/{id}` (the SSR catch-all) → not served as the entity.
- Assert declaratively, from the definitions, that: no monitor type sets
  `api: true`; **no monitor type uses `group: 'content'`**; no monitor type
  declares a `status` field. These three assertions are the durable form of §6.2
  rules 1 to 3, and they fail loudly if a later edit reintroduces the grant.
- Assert the access policy grants `monitor.dashboard_read` and returns
  non-allowed for `view`, `update`, `delete`, and `create`.
- Assert the policy does not declare `SUPPORTS_LISTING_FAST_PATH`.
- **Positive control:** the dashboard's own listings **do** resolve rows, so the
  test proves a working boundary rather than a broken feature.

### 9.3 Duplicate detection

`tests/Unit/SagamokMonitor/ItemKeyTest.php`

- URL variants differing only by tracking params, fragment, trailing slash, or
  host case produce **one** `item_key`.
- Genuinely different URLs produce different keys.
- A rename presenting an identical `content_hash` records `reappeared` against the
  existing item and does **not** mint a second item or a second `public_ref`.
- A `public_ref` is stable across renames and is never reused after a
  `disappeared`.

### 9.4 Update detection

`tests/Unit/SagamokMonitor/ChangeDetectionTest.php`

- Unchanged content over two runs → **zero** events (idempotence).
- Changed body → exactly one `content_changed`.
- Metadata-only change → `metadata_changed`, `change_status` unaffected.
- One absent run → no `disappeared`; two consecutive → exactly one.
- Reappearance → `reappeared`, `disappeared_at` cleared.
- Normalizer: rotating asset hashes, CSRF tokens, and footer timestamps do not
  register as changes.
- `change_status` always equals the projection of the newest non-redacted event
  (the dual-state guard).
- **Re-gating:** a 401, a 403, a login-shell 200, and a `noindex` 200 each produce
  `became_gated` and store **no** hash and **no** snapshot. Assert the side table
  is untouched for that item.

### 9.5 Source health

`tests/Unit/SagamokMonitor/SourceHealthTest.php`

- The `ok` / `degraded` / `failing` thresholds in §3.1.
- The stalled-run case where `last_check_started > last_check_completed` reports
  stalled rather than healthy.

### 9.6 Portal surface

`tests/Integration/SagamokMonitor/PortalStatusTest.php`

- The dashboard renders the access-controlled statement with a month-precision
  date, and contains no claim about remediation or about the presence or absence
  of material anywhere.
- `portal_access_state` and `portal_update_note` declare **no** URL, attachment,
  body, hash, or identifier field — asserted from the definitions, so a later
  field addition fails here.
- `method_note` and `review_note` never appear in a response.
- The monitor source tree contains no `Authorization` header construction, no
  `password`, and no archive or search-index host — a grep assertion, so a future
  collector cannot be added quietly.
- No scheduled task and no CLI command targets the portal.

---

## 10. Implementation order

1. **Entities, provider, field definitions, and the access policy.** Assign every
   `FieldReadLevel` deliberately; the app's boot-time classification overlay fails
   loudly on anything unclassified.
2. **Write §9.2 first.** The auto-exposure lockdown is cheap to assert and
   expensive to discover late. Get it green before any collector exists.
3. **Regenerate `.waaseyaa/field-access-preflight.json` from the production
   schema** (§2). New columns change the fingerprint and a fresh-install artifact
   will not activate in production.
4. **Listings, repository, `view()`.** Write §9.1 before the templates exist.
5. **Side table and the collector**, then the normalizer, re-gating gate, and
   change detection with §9.3 to §9.5.
6. **Schedule entry**, verified with `bin/waaseyaa schedule:list`.
7. **Routes and templates.** Run `bin/lint-copy.php` (no em dashes in
   `templates/`).
8. **Portal status and the issue/official-update editorial surface**, seeded from
   facts already published on the two existing disclosure pages.

Steps 1 to 4 and 7 are independently shippable and deliver the public-site change
record on their own.

---

## 11. Open decisions for the maintainer

1. **Which public URLs seed `monitor_source`,** and the crawl budget. This
   document deliberately does not enumerate them; they belong in configuration,
   not in code.
2. **Retention for `snapshot`** in the side table. Long enough to explain a
   change, short enough not to become an archive.
3. **Whether to supersede `conflict-register.html.twig`'s inline `DATA`** with
   entities. Recommended as a **follow-up**: it is a separate data model, and
   mixing it in would make both harder to review.
4. **Notification on change.** Out of scope. The `monitor_event` log is the
   substrate any future digest would read.
