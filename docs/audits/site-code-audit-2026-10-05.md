# RHT Circle local code audit, October 5, 2026

## Outcome and scope

The application now has a Treaty-wide news front door, Nation/topic discovery and bounded Sagamok collections. Bimaaji guidance is installed for Codex and Claude. Dependency overrides and their merge plugin are retired. Static analysis runs across all 66 application PHP source files at PHPStan level 5, without a baseline or ignored findings.

This is an application maintainability and integration audit of the working tree based on `edf35a4`, including pre-existing unpublished edits. It is not an independent review or a production security qualification. No production deployment, upstream issue mutation or private research migration occurred. The copied production database and local secrets are ignored.

## Repairs made locally

| ID | Finding | Repair and evidence |
| --- | --- | --- |
| APP-01 | Duplicate SQLite connections and path resolution in HTTP and ingest providers | Both consume the kernel `DatabaseInterface`; no app-created database connection remains in `src/`. |
| APP-02 | HTTP boot performed schema changes, campaign writes and historical-count resets while swallowing errors | Removed provider boot initialization. `SiteSchemaInitializer` runs only through `app:initialize`; `MemberToolsSeeder` runs only through `app:seed-member-tools`. Migration errors now propagate. Existing consent instruments remain separate. |
| APP-03 | HTTP and corpus ingestion maintained different publication contexts | `PublicationContext` is shared for homepage, news and community rendering. Ingest includes the homepage and passes the same context as HTTP. Dry-run extraction succeeds. |
| APP-04 | A single community could dominate featured news; community links were inconsistently scoped | Homepage mix caps connected-Nation representation. All 21 desks have filtered news links. Original article filters run through Listing before pagination/access checks. Legacy `circle` article scope is Treaty-wide, not a broken `/communities/circle` link. |
| APP-05 | Sagamok complaints, reporting, petitions and proposed laws were mixed in one card catalogue | Reporting, open questions, member proposals and tools now have separate collapsed collections. Proposals are explicitly labelled advocacy. No authored source pages or consent records were erased. |
| APP-06 | Four JSON forms duplicated parsing and cast nested objects/arrays into fields; string `false` could count as consent | `JsonSubmission` uses Symfony Request decoding, size caps and scalar-object validation. Consent and publication choices require actual boolean `true`. Regression tests cover malformed fields and false-valued consent. |
| APP-07 | Contact storage used `SELECT MAX(id)` to identify an inserted message | Uses the framework insert builder's connection-local identity. Poll setup also uses its inserted identity without re-querying a previously absent slug. |
| APP-08 | Public fallback hash secrets were embedded in providers | Shared `HashSecret` preserves existing dedicated/JWT keys and refuses missing/short private keys; master-secret fallback supports fresh local fixtures. No secret values are stored in this report. |
| APP-09 | Asset versioner hashed JavaScript but the shell used stale/manual script URLs | Both shared scripts now use the asset version. |
| APP-10 | Static analysis found dead code, redundant fallback and revision-interface assumptions | Removed unused admin HTML helper and impossible coordinate fallback; narrowed non-null read-level return; article seed operations validate actual revisionable Node entities. Level 5 passes. |
| APP-11 | Skeleton documentation disagreed with this application and CI skipped lock validation | App-specific README/local setup, shared AGENTS contract, thin CLAUDE entry, Bimaaji ownership manifest, strict Composer validation and static analysis in CI. Portable `composer check` and `composer audit-site`. |
| APP-12 | Static page map inflated the provider and was hard to inspect | Extracted `EditorialPages` as the canonical route/template catalogue. Provider routing remains a further decomposition candidate below. |
| APP-13 | Bootstrap error responses exposed exception messages in production | Error details now depend on debug mode; server-side diagnostics remain available. |
| APP-14 | Fresh setup generated only JWT secret and could not boot alpha.305 | Setup generates a separate `base64:` application master secret when creating a new environment, preserving existing environments. |

## Framework bugs and integration friction

### FW-01: strict Bimaaji graph export cannot complete

- Owner: Waaseyaa Foundation route-composition and AI-agent maintainers; RHT Circle owns subsequent app route migration.
- Reproduction: `php vendor/bin/waaseyaa graph:dump --strict --section=public_surface` on this alpha.305 lock.
- Observed: exit 1, `RouteCompositionException`, `Legacy route contributor: Waaseyaa\AI\Agent\Routing\AgentRouteServiceProvider`.
- Impact: Bimaaji skills install correctly, but the strict graph authority is unavailable. Do not claim a successful graph export or add a permissive private graph engine.
- Acceptance: published compatible package cohort exports all six sections strictly; public/private classifications match runtime routes; app closure routing is migrated to the supported declaration contract without lost methods, redirects or access controls.

### FW-02: field-read preflight conflicts with a stricter host classification

- Owner: Waaseyaa field-read defaults/preflight and AI-pipeline maintainers.
- Reproduction: `php vendor/bin/waaseyaa field-access:preflight --write-artifact`.
- Observed: zero unclassified entries but `ready: false`, conflict `pipeline|*|label`. The host artifact deliberately marks operational pipeline labels internal. The current framework default for that key is public.
- Evidence: `vendor/waaseyaa/field/src/Preflight/FieldAccessPreflightScanner.php` compares live default keys against artifact levels; `vendor/waaseyaa/ai-pipeline/src/AIPipelineServiceProvider.php` registers a pipeline without explicit label-field metadata.
- Impact: local rendering works, but this upgrade is not production-qualified. The stricter host artifact has been retained. The committed local preflight records failure rather than a false pass.
- Acceptance: operational label policy is resolved in the canonical package contract; both runtime reads and preflight agree; existing internal consumer classifications do not silently become public.

### FW-03: legacy database migration sequencing is not self-contained

- Owner: Waaseyaa entity-storage/schema and AI-agent migration maintainers.
- During the local production refresh, `db:init` initially attempted indexes on missing AI-agent columns. Running `schema:sync` first made the migration executable. The database had legacy JSON entity tables.
- Reproduction fixture: old `agent_run` with only `id`, `bundle`, `langcode`, `_data`; alpha.305 upgrade with pending agent queue migrations.
- Impact: the advertised migration command alone is insufficient for this predecessor shape. Local instructions now record the observed order.
- Acceptance: tested upgrade from the predecessor cohort either installs prerequisites itself or gives an actionable preflight before any index mutation; migration order is documented and covered by a fixture.

### FW-04: lifecycle genesis and upgrade steps are fragmented

- Owner: Waaseyaa install/configuration and entity-mutation maintainers.
- The production snapshot required `install:init` for missing canonical configuration and an explicit mutation-authority backfill for 1,276 existing entities, in addition to schema synchronization and 31 pending migrations.
- Impact: a framework update that installs successfully may still fail when existing content is written. This is observed operational friction, not a claim that every upgrade requires these steps.
- Acceptance: one diagnostic command distinguishes fresh install, missing activation, schema drift and authority backfill, with supported ordering and resumable operations.

### HOST-01: native Windows SQLite fixture cleanup warning

- Owner: RHT Circle test lifecycle first; framework connection disposal if an isolated reproduction identifies the retained handle.
- Reproduction: `php vendor/bin/phpunit --filter AnonymousArticleListingTest --display-warnings`.
- Observed: test assertions pass, but teardown `unlink()` reports `Resource temporarily unavailable` for the temporary SQLite file. Tracking kernels, closing DBAL connections and collecting cycles did not resolve it.
- Impact: one warning and a test-owned temporary file can remain after the run. Warning is visible, not suppressed. Framework responsibility has not been proven.
- Acceptance: minimal supported-host comparison, no warning, no retained file, no suppression or weakened assertions. Normal request and CMS behavior must remain covered.

### HOST-02: native Playwright CLI wrapper abort

- Owner: local Node/Playwright CLI integration.
- `npx --package @playwright/cli playwright-cli --help` printed usage then exited 1 with a libuv `UV_HANDLE_CLOSING` assertion on this host.
- Browser checks completed using the existing bundled Playwright SDK and browser. No extra app browser dependency was installed. This is not a Waaseyaa framework defect.

## Further app improvements, with explicit acceptance criteria

| Priority | Owner / area | Remaining issue | Acceptance |
| --- | --- | --- | --- |
| P1 | App routing | AppServiceProvider remains 721 lines and mixes public, forms, admin and machine-readable routes; legacy closure contributions are not strict graph declarations | Separate providers by domain using the supported framework route contract; preserve route/access/redirect evidence and obtain a strict Bimaaji graph. Do not build an app-only route compiler. |
| P1 | App forms and persistence | Signup deduplication and rate-limit checks are check-then-write operations; several operational indexes are non-unique | Add schema migrations and concurrent-write tests for idempotent signup and atomic limits. Preserve historical signatures, counts and consent versions. |
| P1 | App test coverage | Most private operational repositories and admin access paths lack focused integration tests | Add tests for permissions, deduplication, unsubscribe, petition consent separation and throttling, without real messages or private production fixtures. |
| P2 | App content / framework Listing | News topic options are derived from the first original-reporting page plus reviewed digest, not an access-aware full facet catalogue | Define a reviewed topic vocabulary or use a framework facet contract; prove old-page topics remain discoverable without loading full article bodies or bypassing access. |
| P2 | App corpus / framework publishing | The ingest allowlist, static routes, sitemap and managed-article catalogue still have separate ownership; raw graph/corpus readers degrade quietly | Make publication status and corpus ownership explicit. Preserve intentional exclusions for interactive/advocacy pages. Add diagnostics for missing stores and stale corpus rather than treating failure as no content. |
| P2 | App CMS migration | ArticleSeeder's named historical refresh lists may overwrite selected existing fields | Separate one-time content migrations from routine seed/import workflows with revision assertions and clear operator previews. |
| P2 | App presentation | Legacy templates still contain page-local styles/scripts and extensive bespoke layout code | Migrate by component family into shared assets while checking keyboard use, semantics and the four responsive sizes. Do not copy current markup into a generic framework engine. |
| P2 | App build tooling | OG workflow installs a floating Playwright range, and legacy Bash audit requires a byte-identical skeleton entry point | Pin the asset toolchain and use an app-runtime-aware supported audit contract. Portable app checks are available now; legacy deploy qualification remains distinct. |
| P2 | App editorial operations | Digest summaries were reviewed July 26 and original reporting remains uneven across Nations | Source-backed editorial intake across all 21 Nations, visible source/review dates and labelled coverage gaps. Do not invent stories to make coverage look equal. |

## Cleanup and ownership

No recovery archive was created. Active worktrees and authored untracked content were preserved.

Obsolete generated artifacts identified for deletion: `.playwright-cli/`, `output/playwright/`, empty `vendor-dist/`, and unused `composer.local.json.example`. Automatic approval review rejected the scoped PowerShell deletion as `blocked by policy`, despite user authorization. These files remain; ignoring/excluding them is not deletion.

`public/local-preview/` contains three older compiled drafts and has been excluded from deployment build context. It is not deleted automatically because at least one draft contains distinct authored work. Its builders under `scripts/` and `output/` need source reconciliation before retirement. The content inventory records that decision. Private research and active worktrees are outside this cleanup.

Temporary browser audit artifacts live in ignored `var/`; they are development artifacts, not canonical content. Existing historical design/audit documents remain labelled evidence rather than active operating instructions.

## Verification

Final local evidence is recorded here. A successful app check is not a claim that FW-01 or FW-02 is resolved.

- Bimaaji install: first run wrote 12 client targets each; second run reported 12 unchanged for each client and preserved app guidance.
- PHPStan level 5: zero findings, no baseline/suppression, all 66 source files.
- Copy and signature-count lints: pass.
- Corpus dry run: 822 chunks from 81 pages, no writes.
- Browser: homepage, news, Sagamok community and record desk at 360, 768, 1024 and 1440 CSS pixels, HTTP 200, one H1, no horizontal overflow. Nation filter selection, coverage-gap state, 23 selector options (all, Treaty-wide, 21 Nations), and four disclosure collections pass.
- Strict graph: failed, FW-01.
- Field-read preflight: failed, FW-02.
- PHPUnit: 35 tests, 392 assertions, all assertions pass; one native cleanup warning remains HOST-01.
- All 21 Nation routes: HTTP 200 with working filtered-news links.
- Schema dry run: all 33 registered entity tables are up to date.
- Composer dependency/platform validation and Git whitespace checks: pass.

Static-analysis identifier references: [missing method](https://phpstan.org/error-identifiers/method.notFound), [unused method](https://phpstan.org/error-identifiers/method.unused), [always-true comparison](https://phpstan.org/error-identifiers/identical.alwaysTrue). These informed type/dead-code repair, not suppression.
