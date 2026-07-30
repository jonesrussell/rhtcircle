# Sagamok monitoring dashboard — implementation specification

**Status:** implementation-ready for Part A. **Part B is specified but gated** —
see §0 and §12.
**Audience:** whoever implements this in `jonesrussell/rhtcircle`.
**Framework baseline:** `waaseyaa/framework` 0.1.0-alpha.279 or newer.

---

## 0. Read this first: the two halves ship differently

This specification covers two monitors. They are **not** equally ready, and the
difference is deliberate.

**Part A — the public-site change record (§3.1–§3.3 for `public_site`, §4.1–§4.3,
§5, §8, §9).** Ordinary transparency: did the Nation publish something new, and
did something published quietly change or disappear? No sensitive material is
involved. **Build this.**

**Part B — the portal exposure record (everything marked `portal_index`, §6, §7).**
This exists because of a documented, already-public incident: the members-only
portal applied its login check in the browser, so gated pages were delivered
before the login appeared. The live site was fixed **June 3, 2026**, but the
gated area had been captured in the public Internet Archive, permanently. Both
facts are already published with dates and sources at
`/communities/sagamok/members-website-issue` and
`/communities/sagamok/it-accountability`.

Part B is **gated on the §12 preconditions** — an archive exclusion request
filed, written notice to Council and the IT office with a response window
elapsed, and a maintainer decision recorded. The reason is in §6.0: an earlier
draft of this document leaked, through fields that were *Public by design*, a
curated index into an archive of documents that the app's own published record
says contain member names, family details, and passwords printed in plaintext.
Per-field redaction did not catch it because the leak was one level up, in the
composition of individually-harmless public fields.

**If you are implementing this and want to ship something useful this week:
build Part A. Do not build Part B without walking §12 first.**

---

## 1. What this is, and why it is two monitors and not one

Members of Sagamok Anishnawbek currently have no way to answer two ordinary
questions:

1. *Did the Nation publish something new, and did something published quietly
   change or disappear?*
2. *Is material that was supposed to be behind the members-only login still
   reachable by the public?*

Question 1 is routine transparency: a change log for a public website. Question
2 is **responsible-disclosure follow-through**. It exists only because of a
documented, already-public incident: for a period the members-only portal
applied its login check in the browser rather than on the server, so gated pages
were delivered before the login appeared. The live site was fixed on
**June 3, 2026**, but the gated area had by then been captured in the public
Internet Archive, which is permanent. Both facts are already published, with
dates and sources, at `/communities/sagamok/members-website-issue` and
`/communities/sagamok/it-accountability`.

These two questions have **different ethics and therefore different code
paths.** Conflating them would be the central design error, so this
specification keeps them as two separate sources with different collection
rules, different retention, and different exposure:

| | Public site monitor | Portal exposure monitor |
|---|---|---|
| What it watches | The Nation's public website | **One** configured web archive, and only what it *already serves to the public without authentication* (§4.4: no search-index querying) |
| Why | Members deserve a change record | To evidence, and press for the removal of, an exposure that already happened |
| Authenticates? | Never | **Never.** No credentials, no login, no bypass of any control |
| Stores document bodies? | Only a page that passed the re-gating gate (§4.3), `Protected`, never rendered | **Never.** A hash the public never sees, plus generated metadata |
| Halt condition | None, but a page that becomes gated is skipped and reported (§4.3) | Stops collecting on the §7 detection, then **awaits maintainer confirmation** before publishing anything about it |
| Public exposure | Title, URL, dates, change status | The closed list in §6.5: an opaque ref, a generated label, a coarse status, month-granularity dates. No locator, no title, no hash, **no count** |
| Indexable? | Yes | **No** — `noindex, noarchive`, sitemap-excluded while live (§8) |

**The portal monitor is not a scraper of a protected system.** It observes one
third-party archive that is already serving this material to anyone, and records
*that it is still doing so*. It never becomes a second copy of the exposure.

Every safeguard in §6 is structural — field-read levels, a single redaction
boundary, and tests that assert the public projection as a **closed set** rather
than probing for known-bad strings. §6.0 explains why that distinction is the
whole ballgame.

### Non-goals

- Not a document library. It never republishes protected content.
- Not an accusation engine. Per the app's standing guardrails it presents
  sourced, dated facts and the questions members are entitled to ask, and it
  names offices and roles, never private individuals.
- Not a login client. It has no credential store and no code path that
  authenticates to any Sagamok system.

---

## 2. Conformance with existing app patterns

This app has an established shape and one well-known anti-pattern. The
dashboard must follow the former and must not repeat the latter.

**Follow:** articles are `node` + bundle `article`, with collections resolved
through the framework Listing pipeline (`HasListingsInterface::listings()` →
`ListingDefinition` → `ListingResolver`), and templates receive flat arrays
produced by a repository's `view()` method
(`src/Cms/ArticleRepository.php`).

**Do not repeat:** `templates/pages/communities/sagamok/conflict-register.html.twig`
is 1064 lines, most of which is a `var DATA = {…}` JavaScript object literal
holding rosters and vote records. It has no PHP model, no entity, no test, and
no way to query it. It is the nearest existing neighbour to what is proposed
here, and it is exactly what this dashboard must not be. Every fact this
dashboard shows is an entity row, reached through a Listing.

Two framework constraints shape the field design and are not negotiable:

- **Rule G:** a field used in a Listing `filters` or `sorts` must be
  `FieldStorage::Column` and `->indexed()`. `_data`-blob fields raise
  `UnsupportedListingException` at boot. **Decide the facet set before writing
  field definitions.**
- **Preflight:** every new column changes the field-access schema fingerprint,
  so `.waaseyaa/field-access-preflight.json` must be regenerated **from a copy
  of the production schema**, never from a fresh install (see commit
  `c017eb4` and §10).

Also: Listing ids are globally unique across providers or boot fails hard, and
bundle fields must be registered *before* Listing validation runs.

---

## 3. Proposed entity model

Five entity types. Three are the durable record, two are the editorial layer.
All are registered in a new `SagamokMonitorServiceProvider`
(`src/Provider/SagamokMonitorServiceProvider.php`), declared in
`composer.json` `extra.waaseyaa.providers` **after** `CmsContentServiceProvider`.

Each is an attribute-driven `ContentEntityBase` subclass in
`src/Entity/`, following `MythEntry`'s shape.

### 3.1 `monitor_source` — what we watch, and whether the watching is healthy

One row per monitored surface. This is where **source health**, **last-check
time** and the **portal halt switch** live.

| Field | Storage | Read level | Notes |
|---|---|---|---|
| `key` | Column, indexed | Public | Stable slug, e.g. `sagamok_public_site` |
| `label` | Column | Public | "Sagamok public website" |
| `kind` | Column, indexed | Public | `public_site` \| `portal_index` — **drives every policy branch** |
| `origin_url` | Column | Public for `public_site`, **Protected** for `portal_index` | For a portal source this is the *third-party index* being queried, never a portal locator |
| `enabled` | Column, indexed | Public | Operator switch |
| `health` | Column, indexed | Public | `ok` \| `degraded` \| `failing` \| `halted` |
| `last_check_started` / `last_check_completed` | Column, indexed | Public | Unix seconds. **Both**, so a run that never finished is visible as a stall rather than silently reporting stale data as fresh |
| `last_success` | Column, indexed | Public | Last check that completed without error |
| `consecutive_failures` | Column | Public | Drives `health` |
| `last_error` | Data | **Internal** | Diagnostic text may quote a URL or response fragment |
| `halted_at` / `halt_reason` | Column / Data | Public / Public | Set when §7 fires. `halt_reason` is a fixed enum label, not free text |

**Source health is defined, not implied:**

- `ok` — last check completed, `consecutive_failures == 0`.
- `degraded` — 1 or 2 consecutive failures, or `last_success` older than 2× the
  expected interval.
- `failing` — 3 or more consecutive failures, or `last_success` older than 7 days.
- `halted` — collection deliberately stopped (§7). **Terminal without operator
  action**; it is never re-derived from a probe.

A source whose `last_check_completed` is older than its `last_check_started` by
more than one interval is reported as a stalled run, not as healthy.

### 3.2 `monitor_item` — a thing being tracked over time

One row per distinct tracked object (a page, a posted document, an indexed
portal artifact). Stable identity across checks.

| Field | Storage | Read level | Notes |
|---|---|---|---|
| `source_key` | Column, indexed | Public | FK to `monitor_source.key` |
| `item_key` | Column, indexed | Public for `public_site`, **Internal** for `portal_index` | Deterministic identity, see §4.2. Unique with `source_key`. **A portal `item_key` is never public — see §6.5, it is a confirmation oracle** |
| `public_ref` | Column, indexed | Public | An opaque per-item counter (`p-1`, `p-2`, …) assigned at first sight, used for public linking and dedup display. Carries no preimage |
| `title` | Column | Public for `public_site`, **Protected** for `portal_index` | See §6.2: a portal item's own title can leak document content |
| `safe_label` | Column | Public | The **only** human-readable string a portal item ever shows publicly. Generated, not copied (§6.2) |
| `public_url` | Column | Public for `public_site`, **Internal** for `portal_index` | A portal item's locator is never public at any level below Internal |
| `doc_kind` | Column, indexed | Public | `page` \| `document` \| `unknown` — a coarse category, safe for both kinds |
| `change_status` | Column, indexed | Public | `new` \| `unchanged` \| `changed` \| `disappeared` \| `reappeared` |
| `first_seen` / `last_seen` | Column, indexed | Public | Unix seconds |
| `changed_at` | Column, indexed | Public | Last time content changed |
| `disappeared_at` | Column, indexed | Public | Set when a previously-present item stops appearing |
| `event_count` | Column | Public | Denormalized count for listing display |
| `current_hash` | Column | **Protected** | See §6.3 — a hash of a protected document is a confirmation oracle |
| `bytes` | Column | **Protected** for `portal_index` | Size is a weak fingerprint |

`change_status` is a **derived projection** of the newest `monitor_event`, stored
as a column because it is a primary listing facet (Rule G). It must be written
in the same save as the event that causes it; a projection that can disagree with
its event log is the dual-state bug this codebase has been bitten by before.

### 3.3 `monitor_event` — the append-only change log

One row per observed transition. **Never updated, never deleted** — this is the
historical record the dashboard exists to provide.

| Field | Storage | Read level | Notes |
|---|---|---|---|
| `source_key` / `item_key` | Column, indexed | Public | |
| `event_type` | Column, indexed | Public | `appeared` \| `content_changed` \| `metadata_changed` \| `disappeared` \| `reappeared` |
| `observed_at` | Column, indexed | Public | Unix seconds. **Observation** time |
| `effective_at` | Column, indexed | Public | The date the *source* claims, when it publishes one. Nullable |
| `previous_hash` / `new_hash` | Data | **Protected** | The hash pair proving a change without disclosing content |
| `evidence_kind` | Column, indexed | Public for `public_site`; **Protected** for `portal_index` | `direct_fetch` \| `web_archive` — **provenance is a first-class field, not a comment**. `search_index` is not an allowed value, see §4.4 |
| `evidence_ref` | Data | **Internal** | The exact retrieval reference (URL, archive timestamp, query). Never public at any level |
| `evidence_captured_at` | Column | Public for `public_site`; **Protected** for `portal_index` | When the evidence itself was captured. **For a portal item this is an archive capture timestamp, which is an archive primary key — see §6.5** |
| `evidence_month` | Column | Public | Coarsened capture date (`2026-06`) — the public granularity for a portal item |
| `notes` | Data | **Internal** | Operator diagnostics |

**Evidence provenance** is the triple (`evidence_kind`, `evidence_captured_at`,
`evidence_ref`), where the first two are public and the third is Internal. A
member can see *that* a change is backed by a web-archive capture taken on a
given date, and staff can see exactly which capture, and the locator never
becomes public.

### 3.4 `monitor_issue` — the current-issues tracker

The editorial layer: a member-facing issue, hand-authored, linked to the
machine-observed record and to official updates.

| Field | Storage | Read level | Notes |
|---|---|---|---|
| `slug` | Column, indexed | Public | |
| `title` | Column | Public | Framed as a question or a neutral statement of what is outstanding |
| `status` | Column, indexed | Public | `open` \| `awaiting_response` \| `partly_answered` \| `resolved` \| `withdrawn` |
| `severity` | Column, indexed | Public | `information` \| `concern` \| `urgent` |
| `opened_at` / `status_changed_at` / `closed_at` | Column, indexed | Public | |
| `summary` | Data | Public | Short member-facing text |
| `what_is_asked` | Data | Public | The specific ask, per the app's questions-not-accusations rule |
| `related_article_slugs` | Data | Public | Links to `node`/`article` records |
| `related_item_keys` | Data | Public | Links to `monitor_item` rows |

`resolved` requires `closed_at`. A `monitor_issue` is never auto-created;
machine observation proposes, a member decides.

### 3.5 `monitor_official_update` — what the Nation actually said

The "connected to related official updates" half of the tracker. Separate from
`monitor_issue` so that an official update can be recorded whether or not it
answers anything.

| Field | Storage | Read level | Notes |
|---|---|---|---|
| `issue_slug` | Column, indexed | Public | FK to `monitor_issue.slug` |
| `published_at` | Column, indexed | Public | |
| `source_label` | Column | Public | "Council minutes, June 3 2026" |
| `source_url` | Column | Public | A **public** URL only. A portal-only source is recorded by label with an empty URL |
| `summary` | Data | Public | Neutral description |
| `answers_ask` | Column | **Not rendered** | `yes` \| `partly` \| `no` \| `unclear` — internal editorial state only. **Deliberately not `indexed` and never displayed: see below** |

**`answers_ask` must not be rendered, sorted, or filtered.** A machine-readable,
sortable public scorecard of `no` against the Nation's official statements is a
conclusion about conduct, not a sourced fact framed as a question, and it breaks
the app's hard rule 2. (The 2026-07-15 exception is scoped explicitly to
`/communities/sagamok/member-accountability-resolution`; a new route gets rules
1 to 4 in full.) Keep the field for editorial triage; render the **ask and the
official update side by side** and let the member judge, which is what rule 2 is
for.

The same reasoning applies to `monitor_issue.severity` and the open-issue
`opened_at` sort: `severity: urgent` plus a public `opened_at`, sorted, composes
into an automatically escalating countdown clock against an office, generated
forever with no author taking responsibility for the claim. The prose form of
that argument already exists on `it-accountability`, written and dated by a named
member. **Render `opened_at` and let the reader do the arithmetic; do not render
a computed "days overdue" figure, and do not sort the public list by
`severity`.** `severity` may order the maintainer's own view.

---

## 4. Collection

### 4.1 Scheduled entry point

This app has **no scheduled tasks today**; this adds the first. Per the
framework contract:

- `src/Schedule/SagamokMonitorSchedule.php` implements `ScheduleEntriesInterface`,
  marked `@api`, declaring `register(ScheduleInterface $schedule): array`.
- Two tasks: `sagamok:monitor-public` (hourly) and `sagamok:monitor-portal`
  (daily, and only while §7 has not fired).
- Two CLI commands under `src/Command/` so a run is reproducible by hand:
  `sagamok:monitor-public` and `sagamok:monitor-portal`, both supporting
  `--dry-run`.
- Verify discovery with `bin/waaseyaa schedule:list`.

Runs must be **idempotent**: two runs over unchanged upstream state produce zero
new `monitor_event` rows. This is the single most important behavioural property
and it has its own test (§9).

### 4.2 Identity and duplicate detection

`item_key` is derived, never taken from a volatile string:

- `public_site`: `sha256(normalized_url)`, where normalization lowercases the
  host, drops the fragment, strips known tracking parameters, and collapses a
  trailing slash. This is what makes `?utm_source=…` variants one item rather
  than many.
- `portal_index`: `sha256(source_key . '|' . stable_document_identifier)`, where
  the identifier is derived from the archive/index record, never from a
  credentialed locator.

Duplicate detection is therefore **structural** — the primary key does the work.
On top of that, a **near-duplicate guard**: when a new `item_key` appears in the
same run as a `disappeared` item on the same source with an identical
`current_hash`, record `reappeared` (or a move) on the existing item rather than
minting a new one. Without this, one upstream URL rename produces a spurious
"document disappeared" plus a spurious "new document", which is precisely the
kind of false alarm that would discredit the dashboard.

### 4.3 Update detection

For each item observed in a run:

1. Compute `new_hash` over the **normalized** content (see below).
2. No existing row → `appeared`, `change_status = new`.
3. Hash differs from `current_hash` → `content_changed`, `change_status =
   changed`, `changed_at` set, `previous_hash`/`new_hash` recorded.
4. Hash equal, tracked metadata differs → `metadata_changed`,
   `change_status` unchanged.
5. Hash equal, metadata equal → **no event**, `last_seen` refreshed only.
6. Previously present, absent this run → `disappeared`, `disappeared_at` set.
   Requires **two consecutive** absent runs before the event is written, so a
   single upstream timeout is not reported as a removal.
7. Absent then present again → `reappeared`.

**Re-gating gate (public-site collector).** Before hashing anything, the
public-site collector must check whether the page is still genuinely public, and
**skip and alert rather than store** if it is not:

- HTTP `401`/`403`, or a redirect to a login path → record `became_gated`, store
  no body, no hash.
- A `200` whose body is a login shell or carries `noindex` → same.

This matters because the original incident *was* a `200` response with a
client-side login overlay. Without this branch, a regression of that bug, or a
currently-public page being moved behind the portal, would have the public-site
collector fetch `200`, hash it, and store the body — making the app built to
document that exposure into a second copy of it, through the code path §0 calls
"routine transparency". A `became_gated` observation is a **finding to report**,
not content to collect.

**Body storage.** The public-site collector stores a body **only** for a page
that passed the re-gating gate, in a `body_snapshot` field
(`FieldStorage::Data`, read level **`Protected`**, `public_site` items only —
`portal_index` items have no such field at all). It is never rendered; it exists
so a change can be explained. Cap it, and prune snapshots older than the
retention window. If §6.6 Option A is chosen, keep snapshots in the collector's
side table instead and the entity carries no body at all — preferred.

Content normalization before hashing must strip the things that change on every
fetch without the content changing: session ids, CSRF tokens, cache-buster query
strings, timestamps in footers, and rotating CSS/JS asset hashes. Un-normalized
hashing yields "everything changed, every hour", which is the same as no signal
at all. The normalizer is pure and unit-tested (§9).

### 4.4 Portal collection rules (hard constraints)

**Allowed surface: `web_archive` only.** `search_index` is **not** an allowed
`evidence_kind`. A daily automated query against a search index for another
party's members-only material reads, plainly, as systematic monitoring of that
party's exposed material, and it undermines the legitimacy the existing record
rests on: that the original discovery was **incidental**, in ordinary search
results. A CDX-style query against one fixed host in a web archive is narrow,
documented, attributable, and defensible. Use only that.

The portal collector **must**:

- read only from the single configured web-archive surface, which already serves
  the material publicly and unauthenticated;
- send no credentials, no cookies, and no `Authorization` header to any Sagamok
  host;
- have **no code path that authenticates**, and no credential configuration to
  supply one;
- respect the halt switch (§7) before every request;
- record `evidence_kind` of `web_archive` only — never `direct_fetch`.

It **must not**:

- fetch from the portal directly, follow a login flow, replay a bypass, or use
  any technique described in the disclosure record;
- persist a document body, an excerpt, a filename, or any locator outside an
  `Internal` field;
- store or emit a signed/tokenized URL, a query signature, or any other
  access-granting parameter, in any field at any read level. These are stripped
  at ingest, before persistence, not at render time.

A code review checklist item: `grep` the portal collector for `Authorization`,
`password`, `login`, `cookie`. All must be absent.

---

## 5. Listings

Registered by `SagamokMonitorServiceProvider` implementing
`HasListingsInterface`. Ids are prefixed `sagamok_monitor_` for global
uniqueness. Every field named in `filters`/`sorts` below is declared
`FieldStorage::Column` + `->indexed()` in §3, per Rule G.

| Listing id | Entity / bundle | Filters | Sorts | Page size | Serves |
|---|---|---|---|---|---|
| `sagamok_monitor_sources` | `monitor_source` | `enabled = true` | `kind` asc, `key` asc | 50 | Source-health strip |
| `sagamok_monitor_public_items` | `monitor_item` | `source_key = sagamok_public_site` | `last_seen` desc | 25 | Public-site table |
| `sagamok_monitor_public_changes` | `monitor_item` | `source_key = …public_site`, `change_status IN (new, changed, disappeared, reappeared)` | `changed_at` desc | 25 | "What changed" |
| `sagamok_monitor_portal_items` | `monitor_item` | `source_key = sagamok_portal_index` | `last_seen` desc | 25 | Portal table (**redacted projection only**, §6.2) |
| `sagamok_monitor_timeline` | `monitor_event` | none | `observed_at` desc | 50 | Combined change timeline |
| `sagamok_monitor_issues_open` | `monitor_issue` | `status IN (open, awaiting_response, partly_answered)` | `severity` desc, `opened_at` desc | 25 | Current-issues tracker |
| `sagamok_monitor_issues_resolved` | `monitor_issue` | `status = resolved` | `closed_at` desc | 25 | Resolved archive |
| `sagamok_monitor_updates` | `monitor_official_update` | none | `published_at` desc | 25 | Official-updates rail |

Pagination and filtering come from the Listing pipeline; the templates must not
slice arrays themselves. `SagamokMonitorRepository` mirrors
`ArticleRepository`: resolve a definition, map each entity through a `view()`
method, hand flat arrays to Twig.

**`view()` is the redaction boundary.** For a `portal_index` row it emits
`safe_label`, `doc_kind`, dates, `change_status` and `evidence_kind` — and
structurally cannot emit `title`, `public_url`, `current_hash`, `bytes`, or
`evidence_ref`, because it never reads them. Redaction is one auditable method,
not a rule spread across templates.

---

## 6. Exposure boundaries

### 6.0 The failure mode this section exists to prevent

An earlier draft of this document assigned read levels field by field, reasoned
carefully about each one, and still leaked. The leak was not in any single
field. It was in the **composition of fields that were each individually
harmless and Public by design**:

- `item_key` was `sha256(source_key . '|' . stable_document_identifier)` and
  Public. `source_key` is a published constant and the identifier derives from a
  public archive record for a host named on two existing public pages — so the
  preimage space is *publicly enumerable*. Anyone could pull the archive index,
  hash each candidate, and match. That is the same confirmation-oracle argument
  §6.3 correctly applies to content hashes, except easier, because the search
  space is smaller.
- `evidence_captured_at` was Public, and an archive capture timestamp is an
  archive **primary key**. Publishing an ordered list of them for a known host
  is publishing a filtered view of that archive's index. `evidence_ref` being
  Internal bought nothing, because the reader reconstructs it.
- `curated_label` (`minutes`, `financial`, `membership`) plus a public capture
  date states, in effect, *"the artifact captured on this date is the membership
  list."* Per the app's own published record those documents contain member
  names, family details, and passwords printed in plaintext. A reader who wants
  the documents does not need a locator; they need to know which of the
  archive's noise is worth digging through. That was being supplied deliberately,
  as the feature's value proposition.
- `event_count` per item plus fixed-page-size listings made the tracked set
  enumerable and published a live "still retrievable: N" tally.

None of this would have been caught by the redaction test, because every one of
those fields was Public **on purpose**.

**Two rules follow, and they govern the rest of this section:**

1. **Reason about the public projection as a whole, not field by field.** Before
   adding any Public field to a `portal_index` row, ask what it composes with.
2. **The threat model is a reader who wants the documents**, not a careless
   reader. Every safeguard below is written against that adversary. §6.3 was the
   only part of the earlier draft that did this, and it was the only part that
   got the answer right.

### 6.1 What the framework gives us, and what it does not

Available today: `FieldReadLevel` (`Public` / `Protected` / `Internal`) enforced
by `FieldReadGuard`, plus listing-level filtering. The app already uses both,
and already has a strict boot-time classification overlay
(`FieldAccessClassificationProvider`) that **fails loudly** on an unclassified
or conflicting field rather than defaulting open.

**Not available today: there is no member/staff role tier in this app.** Every
public route is `->allowAll()`; there are no `AccessPolicyInterface`
implementations and no `_permission`/`_role`/`_gate` route options. So
"member-safe" cannot mean "shown to logged-in members" without net-new work.

**Therefore this specification narrows the rendered portal surface until it is
public-safe, and drops the member-tier requirement explicitly rather than
quietly.** Be clear about what that trade is: the requirement said "expose only
member-safe metadata", which presupposes a member audience; this document does
not deliver a member audience, it removes everything that is not safe for
everyone.

There is a stronger reason than convenience, and it is decisive. The Circle is
arm's-length from the Nation by charter and **has no access to the membership
roll.** Any "members-only" tier here would gate on *self-asserted* membership —
a tier anyone can join. A tier anyone can join, holding pointers to archived
documents containing member PII and plaintext passwords, is **worse than no
tier**, because operators will place genuinely sensitive material behind it
believing it is protected. So the absence of a member tier is not merely a
constraint to work around; building one would be the wrong call.

If a real tier ever becomes possible (an authenticated membership check the
Nation itself vouches for) it is an additive change: an `AccessPolicyInterface`
on these types plus a `_gate` route option, with the Protected fields becoming
visible to that tier. The levels below are assigned to make that upgrade
mechanical.

**But note the consequence for storage, which the earlier draft got wrong.** In
this app, with zero protected-read policies registered, a `Protected` read
**throws for every caller** and an `Internal` read throws without an audited
capability (verified: `FieldReadGuard` requires `isAllowed()`, and
`EntityAccessHandler::checkProtectedFieldRead()` returns `neutral` with no
policy, which is not allowed). So "Protected" here means *readable by nobody*,
not *not rendered publicly*. Two things follow:

- §4.3's change detection cannot read `current_hash` through the entity API.
  **The sanctioned path is §6.6.** Do not discover this mid-implementation and
  improvise a bypass; `EntityReadRuntime::installGuard(null)` is a public static
  and disabling the guard process-wide would silently void this whole section.
- Any claim that "staff can see" a Protected or Internal value is **false** as
  specified. §3.3's evidence chain is write-only unless §6.6 is implemented.

### 6.2 `safe_label`: generated, never copied

A portal item's own title is untrusted for display — a filename like
`Band-Member-List-2026-final.xlsx` leaks both content and PII. `safe_label` is
therefore **generated from a closed vocabulary**, never copied from upstream:

```
"<doc_kind> indexed <month year>"   ->  "Document indexed June 2026"
```

Optionally a coarse, curated category chosen by a member from a fixed enum
(`minutes`, `financial`, `membership`, `other`), never inferred from the
filename. If a member curates a label it is stored in a separate
`curated_label` field and is that member's words, subject to the same guardrails
as any other page copy.

### 6.3 Why a hash is Protected

A hash of a protected document is a **confirmation oracle**: anyone holding a
candidate copy can hash it and confirm a match, turning a leaked file into a
verified one. Publishing hashes would therefore make the exposure worse. Hashes
are `Protected`, appear in no public projection, and exist only to prove
*that* something changed.

### 6.4 The prohibition list, mapped to enforcement

| Never exposed | How that is guaranteed |
|---|---|
| Portal passwords / credentials | No credential field exists in any entity; no config key; the collector has no auth code path |
| Direct bypass links | `public_url` is `Internal` for portal items; `view()` never reads it; ingest strips locators from all non-Internal fields |
| Access-granting signatures and tokens | Stripped at **ingest** (before persistence) from every URL-shaped value; a dedicated stripper with its own unit test |
| Member PII | No PII field exists; `safe_label` is generated from a closed vocabulary; upstream titles are `Protected` and never rendered for portal items |
| Raw protected documents | No body/blob/attachment field exists for `portal_index`; the collector never persists content, only a hash |

The petition-signature record on other pages is unrelated to this dashboard and
is not read by it.

### 6.5 The public projection of a portal item, exhaustively

This is a **closed list**. A `portal_index` row renders exactly these and
nothing else. Adding a field to it requires redoing the §6.0 composition
analysis.

| Rendered | Why it is safe |
|---|---|
| `public_ref` (`p-1`, `p-2`, …) | An opaque counter assigned at first sight. No preimage, no derivation from any upstream value |
| `safe_label` | Generated from a closed vocabulary (§6.2), never copied |
| `doc_kind` — **`page` or `unknown` only** | The `document` value and any `curated_label` are **withheld** for portal items, because category plus date is the triage index described in §6.0 |
| `change_status` | A five-value enum |
| `evidence_month` (`2026-06`) | Month granularity. **Never** the exact `evidence_captured_at`, which is an archive primary key |
| `first_seen` / `changed_at` / `disappeared_at`, **coarsened to the month** | Same reason |

**Withheld from the public projection entirely:** `item_key`, `title`,
`public_url`, `current_hash`, `bytes`, `event_count`, `evidence_kind`,
`evidence_captured_at`, `evidence_ref`, `curated_label`, and **any total or
per-item count**. There is no public "still retrievable: N" figure — that number
is an advertisement, and it is exactly what a reader who wants the documents
acts on.

The public portal section therefore says, in substance: *material from the
members-only area remains publicly retrievable through a web archive as of
<month>; N is not published; here is what is being asked and where the record
is.* That is enough for a member to know the exposure is unresolved and to press
for it. It is not enough to help anyone find anything.

### 6.6 The sanctioned read path for Protected and Internal fields

Because `Protected` and `Internal` reads throw for every ordinary caller
(§6.1), the collector must not read them through the entity API. Choose **one**
of these and record the choice:

**Option A (preferred — smaller).** Do not store `current_hash` or
`evidence_ref` on the entity at all.
- Keep `current_hash` in a **non-entity side table** the collector owns
  (`DatabaseInterface` directly, which the app's own rules permit for
  supporting tables). The collector reads and writes it freely; it is never
  reachable through an entity projection, so it cannot leak through `view()`.
- Keep `evidence_ref` **out of the application entirely** — in an operator
  notebook outside the deployment. The app then holds no locators at all and
  §6.4's guarantee becomes trivially true rather than enforced.

**Option B.** Wire the framework's audited reader
(`Waaseyaa\Audit\AuditedFieldRead` with a `CliFieldReadCapabilityIssuer`
capability: CLI-only, TTL-bounded, reason-scoped, ledgered) and have the
collector read through it. More moving parts, but it keeps everything in one
store and every privileged read is logged.

**Do not** register a permissive `ProtectedReadPolicyProviderInterface` to make
Protected reads generally succeed, and **do not** call
`EntityReadRuntime::installGuard(null)`. Either would re-open every field in the
model, not just the one being read.

Note also: read levels govern the **accessor only**. These values sit in
plaintext columns in the SQLite file on a shared host. See §12.6.

### 6.7 Read levels and the Listing pipeline

The framework's Listing pipeline has **no read-level awareness** — nothing stops
a `ListingDefinition` from filtering or sorting on a `Protected` column, and
such a facet would be a working confirmation oracle driven by a query parameter
while never rendering a value. It would pass the §9 redaction test.

**Rule: no `Protected` or `Internal` field may appear in any Listing `filters`
or `sorts`, and no listing filter may be driven by a request parameter.**
Asserted in §9 by reading the definitions, not by inspection.

This composes awkwardly with Rule G (facets must be column-backed), and the
resolution is: **if a value needs to be a facet, it must be public-safe.** If it
cannot be public-safe, it cannot be a facet. That is why `change_status` is a
coarse enum and `current_hash` is not filterable.

---

## 7. The halt condition

**Rule: the portal monitor stops collecting once the material is properly
access-controlled.** The monitor exists only because the material is publicly
reachable; when that stops being true, continuing to look would be
indefensible.

### 7.1 What the app can actually know, and what it must not claim

An earlier draft had the dashboard announce, on detection, that *"the material is
no longer publicly reachable."* **That claim must never be published.** The app
does not know it. All it knows is that its own queries stopped returning
results, and every one of the following produces that same observation while the
material stays retrievable:

- an index or archive API changes a parameter, a pagination default, or a
  continuation token the collector does not follow, and answers `200` with an
  empty result set;
- a surface soft-blocks an automated client with `200`-and-empty rather than an
  error, which §7.2's "responds normally" test would accept;
- a `robots.txt` change causes an archive to *hide* existing captures from public
  browsing — reversible at any time by another `robots.txt` edit, while the halt
  stays latched and the page keeps asserting closure;
- remediation covers the tracked set and misses items the monitor never indexed,
  so closure is declared over a set that was never measured.

Publishing an unverified all-clear about members' data is **the specific act the
two existing disclosure pages hold the Nation accountable for** — a written
assurance that there had been no security issue. This dashboard must not repeat
it with better instrumentation.

**The published statement is therefore scoped to the observation:** *"As of
<date>, the surfaces we query no longer return this material, and collection has
stopped."* Never *"the material is no longer publicly reachable."* And the
closing statement is rendered only after a **maintainer confirms** it (§7.3);
detection alone stops collection, it does not publish a conclusion.

### 7.2 Detection

For **two consecutive scheduled runs**: every previously-tracked portal item is
absent, **and** a **positive-control probe** succeeds. The positive control is a
known-present, unrelated capture on the same surface, fetched in the same run:
if the control also comes back empty, the query itself is broken and the run is
recorded as `failing`, **not** as remediation. Without this control, "our query
returned nothing" and "the material is gone" are indistinguishable, which is
what makes the false all-clear possible.

On detection, in one save:

1. `monitor_source.enabled = false`, `health = halted`, `halted_at = now`,
   `halt_reason = queries_no_longer_return_material`. (The reason names the
   observation, not a conclusion about the world.)
2. A `monitor_event` of `not_returned` per still-tracked item — **not**
   `disappeared`. `monitor_event` is append-only and uncorrectable, so it must
   not be seeded with a claim that later proves false.
3. `monitor_source.awaiting_confirmation = true`. The dashboard shows that
   collection has stopped pending review; it does **not** yet show a closing
   statement.

### 7.3 Confirmation and re-check

- A **maintainer** reviews and either publishes the scoped closing statement
  (§7.1) or records that the halt was a false positive and re-enables.
- The halt is **latched**: `sagamok:monitor-portal` checks `enabled`/`health`
  before any network call and exits immediately. Nothing automatic un-halts it.
- Because a latch with no review trigger is a permanent blind spot, a halted
  source gets a **quarterly reminder task** to re-check by hand. A regression
  (the material returns) is otherwise invisible by construction.
- An archive **exclusion or takedown succeeding** is the good outcome and looks
  identical to a query failure; §3.1's `failing` state must not silently absorb
  it. Record the exclusion request (§12.1) so the halt can be attributed.

The halt is **latched**. `sagamok:monitor-portal` checks `enabled` and `health`
before any network call and exits immediately when halted. Re-enabling is a
deliberate operator action with a recorded reason; nothing automatic un-halts
it. An operator may also halt manually at any time, with the same effect
(`halt_reason = operator`).

Historical `monitor_event` rows are **never deleted** by a halt. The public
record of the exposure having happened is the point; only collection stops.

---

## 8. Routes and rendering

| Route | Path | Renders |
|---|---|---|
| `sagamok-monitor` | `/communities/sagamok/monitor` | Dashboard: source-health strip, current issues, combined timeline |
| `sagamok-monitor-issue` | `/communities/sagamok/monitor/{slug}` | One issue: the ask, status history, related articles, official updates |

Both `->allowAll()`, GET only, following the app's existing route shape
(`SiteController` method + route row). Both are already covered by the
`/communities` prefix in `config/waaseyaa.php` `session.stateless_paths`, so
they are cookie-free for anonymous readers.

**Both routes must send `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet`
while Part B is live**, and must be excluded from `SitemapController`. A page
whose entire premise is that archive captures are permanent and beyond recall
must not itself be captured and preserved, together with whatever index it
renders. The app already has this exact pattern in
`SiteController::reviewPage()`. Once the §7 halt has fired and been confirmed,
the historical record may be indexed if the maintainer chooses; until then it
must not be.

Templates extend `base.html.twig`, receive **flat arrays only**, and contain no
data literals. Guardrails apply to every string: sourced facts framed as
questions, no accusations, offices and roles rather than private individuals, and
no em dashes (`bin/lint-copy.php` enforces the last one).

Each rendered row shows the four things the dashboard promises: **when it
appeared, changed, or disappeared** (from `first_seen` / `changed_at` /
`disappeared_at`), **its change status**, **the source's health and
last-check time**, and **the evidence kind and capture date** backing it.

A visible "last checked" and health badge per source is required, not optional:
a monitoring dashboard that silently stops checking is worse than no dashboard,
because it invites members to read a stale page as current.

---

## 9. Tests

App conventions: two suites (`Unit`, `Integration`), plain `TestCase`, no
PHPUnit attributes, real kernel over a temp SQLite file initialized by shelling
out to `vendor/bin/waaseyaa db:init`, `APP_ENV=testing`, `APP_DEBUG=false`, and
**no** `WAASEYAA_DEV_FALLBACK_ACCOUNT` — the anonymous path is what must be
asserted, since a dev-fallback account masks field-read denials. Expectations
derive from seed/fixture data, never hardcoded counts.

**Redaction (`tests/Integration/SagamokMonitor/PortalRedactionTest.php`)**
- Seed a `portal_index` item whose `title`, `public_url`, `current_hash` and
  `evidence_ref` all contain distinctive sentinel strings.
- Request `/communities/sagamok/monitor` anonymously; assert **no sentinel
  appears anywhere in the response body**.
- Assert the same for the issue detail route and for any JSON the page consumes.
- Assert `safe_label` *does* appear, so the test proves redaction rather than an
  empty page.
- Assert a URL carrying a signature/token is stripped at ingest: persist through
  the real collector path, then read the row back and assert the signature is
  absent from every field, including `Internal` ones.

**Duplicate detection (`tests/Unit/SagamokMonitor/ItemKeyTest.php`)**
- URL variants differing only by tracking params, fragment, trailing slash or
  host case produce **one** `item_key`.
- Genuinely different URLs produce different keys.
- A rename presenting the same content hash records `reappeared` on the existing
  item and does **not** mint a second item.

**Update detection (`tests/Unit/SagamokMonitor/ChangeDetectionTest.php`)**
- Unchanged content over two runs → zero events (the idempotence property).
- Changed body → exactly one `content_changed` with both hashes.
- Metadata-only change → `metadata_changed`, `change_status` unaffected.
- One absent run → no `disappeared`; two consecutive → exactly one.
- Reappearance → `reappeared`, `disappeared_at` cleared.
- Normalizer: rotating asset hashes, CSRF tokens and footer timestamps do not
  register as changes.
- `change_status` always equals the projection of the newest event (the
  dual-state guard).

**Access boundaries (`tests/Integration/SagamokMonitor/AccessBoundaryTest.php`)**
- Anonymous requests to both routes are 200 and leak no `Protected`/`Internal`
  field value.
- `FieldReadLevel` assignments match §3 exactly, read from the definitions —
  so a later field addition that forgets a level fails this test rather than
  shipping open.
- No entity in the model declares a field capable of holding a credential or a
  document body for `portal_index`.
- The portal collector source contains no `Authorization`/`password`/`login`
  code path.
- Both routes send `X-Robots-Tag` with `noindex` and `noarchive`, and neither
  appears in the sitemap.

**Composition, not just fields (`tests/Integration/SagamokMonitor/PublicProjectionTest.php`)**

The sentinel test above cannot catch a leak through a field that is Public **by
design**, which is how the earlier draft leaked (§6.0). This test asserts the
closed list directly:

- The public projection of a `portal_index` row contains **exactly** the §6.5 key
  set — asserted as a set equality, so adding any new key to `view()` fails until
  someone revisits §6.0. This is the test that would have caught `item_key`.
- No portal `item_key` value appears in any response, and `item_key` is `Internal`
  for `portal_index`.
- No exact `evidence_captured_at` value appears in any portal response; only
  `evidence_month`.
- No count, total, or `event_count` for portal items appears in any response.
- `curated_label` and `doc_kind: document` never appear for portal items.
- **No `ListingDefinition` names a `Protected` or `Internal` field in `filters`
  or `sorts`** — read from the registry and cross-referenced against the field
  definitions (§6.7). The framework does not enforce this.
- `answers_ask` never appears in any response, and no listing sorts by it or by
  `severity` (§3.5).

**Halt (`tests/Integration/SagamokMonitor/HaltConditionTest.php`)**
- Two consecutive fully-absent runs **with the positive control succeeding** →
  source `halted`, `halt_reason = queries_no_longer_return_material`,
  `enabled = false`, `awaiting_confirmation = true`.
- **Absent items with the positive control ALSO empty → `failing`, NOT halted.**
  This is the false-all-clear guard and the most important assertion here.
- A surface *error* does not halt (it degrades health instead).
- Once halted, a further run makes **no network call** and writes no event.
- Historical events survive the halt.
- Halt is latched: a subsequent run where items reappear does not auto-re-enable.
- Events written at halt are `not_returned`, never `disappeared` (§7.2), so the
  append-only log carries no claim that could later prove false.
- **Until a maintainer confirms, no closing statement is rendered** — assert the
  response contains the "collection has stopped, pending review" wording and does
  **not** contain any phrasing asserting the material is no longer reachable.

**Source health (`tests/Unit/SagamokMonitor/SourceHealthTest.php`)**
- The `ok`/`degraded`/`failing` thresholds in §3.1, including the stalled-run
  case where `last_check_started > last_check_completed`.

---

## 10. Implementation order

1. **Entities + provider + field definitions.** Assign every `FieldReadLevel`
   deliberately; the boot-time classification overlay fails loudly on anything
   unclassified.
2. **Regenerate `.waaseyaa/field-access-preflight.json` from a copy of the
   production schema.** New columns change the fingerprint, and a fresh-install
   fingerprint will not activate in production (see commit `c017eb4`).
3. **Listings + repository + `view()` redaction boundary.** Write the redaction
   test before the templates exist.
4. **Public-site collector + CLI command**, then the normalizer and change
   detection with their unit tests.
5. **Portal collector**, with the §4.4 constraints and the halt logic, tests
   first.
6. **Schedule entries**, verified with `bin/waaseyaa schedule:list`.
7. **Routes + templates.** Run `bin/lint-copy.php`.
8. **Issue/official-update editorial surface**, seeded from the facts already
   published on the two existing disclosure pages.

Ship 1 to 4 and 7 first — the public-site change record is independently
useful and carries none of the portal sensitivity. The portal half (5) should
land only once its tests, redaction boundary and halt switch are all green.

---

## 11. Open decisions for the maintainer

1. **Which third-party index surfaces** the portal monitor may query, and under
   what terms of service. This specification deliberately does not name one;
   whichever is chosen must be recorded in `monitor_source.origin_url` and must
   serve the material publicly and unauthenticated.
2. **Whether to supersede `conflict-register.html.twig`'s inline `DATA`** with
   entities in the same effort or a follow-up. Recommended follow-up: it is a
   separate data model and mixing them would make both harder to review.
3. **Whether a real members-only tier is wanted.** Not required by this
   specification (§6.1) and additive if adopted later.
4. **Notification on change.** Out of scope here. The `monitor_event` log is the
   substrate any future digest would read.

---

## 12. Part B preconditions (the gate)

Part B does not begin until all of these are done and recorded. They are ordered.

### 12.1 Ask for removal before publishing about it

§1 says the portal monitor exists "to press for the removal of" the exposure. The
mechanism it chooses is publication, and publication is the more harmful and less
reversible of the two available moves. So removal is requested **first**:

1. File an **archive exclusion request** for the affected host with the archive
   operator, and record the date and reference.
2. Give **written notice** to Chief and Council and the IT office: what remains
   publicly retrievable, that an exclusion has been requested, and that a
   member-facing record will follow. State a response window.
3. Let the window elapse.

Only then does Part B's public surface go live. If the exclusion succeeds in the
meantime, Part B may never need to ship, which is the best outcome available.

### 12.2 Record a maintainer decision

A short dated note answering: *does publishing this metadata make the exposure
worse than not publishing it?* §6 answers "how to redact" thoroughly and does not
answer this. It is a judgement, it belongs to the maintainer, and it should be
written down before the code exists rather than inferred from the code
afterwards.

### 12.3 Terms of service

Name the web-archive surface and confirm its terms permit this use (§11.1). The
whole safety argument in §1 rests on the surface already serving the material
publicly; that has to be true of the specific surface chosen.

### 12.4 Correction and erasure path

`monitor_event` is append-only, and the existing disclosure pages say
"questions and corrections are welcome". Immutability is the right default and
the wrong absolute. Define, before shipping: an operator **redaction** that
suppresses a row from every projection with a recorded reason and a retained
stub, so a member who says *"that row is about my family's records"* has a
procedure rather than a promise you will have to break.

### 12.5 Threat-model note

One paragraph naming the adversary: a reader who wants the documents. Check each
Public field in §6.5 against it. §6.0 exists because the earlier draft reasoned
against a careless reader everywhere except §6.3.

### 12.6 At rest and in transit

- The SQLite file holds Internal locators and Protected hashes **in plaintext**
  and lives on a host shared with another site. Set file permissions
  deliberately.
- **Exclude the monitor tables from any dump or backup that leaves the host**, or
  encrypt it.
- §10.2 has you commit a preflight artifact generated from the production schema
  into a public repository. It is schema-only today; keep it that way. **No row
  data in committed artifacts.**
- If a JSON endpoint is added for the dashboard, it is a route and gets the same
  redaction boundary and the same §9 assertions. §9 currently asserts redaction
  "for any JSON the page consumes"; if no such endpoint exists, there is nothing
  to assert and §8 defines none.
