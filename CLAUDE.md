# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this application.

## Overview

The public home of the **Robinson Huron Treaty Members' Transparency Circle** (rhtcircle.ca):
an independent, member-led, treaty-wide transparency movement across all 21 Robinson Huron
Treaty signatory First Nations. A Waaseyaa application built on the
[Waaseyaa framework](https://github.com/waaseyaa/framework).

It is **NOT** the Robinson Huron Treaty Litigation Fund and is **NOT** an OIATC product.
It is arm's-length from OIATC by design, to keep the Circle member-led and OIATC non-partisan.
Deploys to the same Pi as oiatc.ca, but is its own app and its own repo.

## Identity and guardrails (hard rules)

These are not style preferences. They are the reason the Circle works and the reason it carries
less legal exposure than naming-and-blaming. Apply them to every page and every edit:

1. **Independent, member-led, non-partisan.** Not the Fund, not affiliated with it, not a council
   committee. Transparency and member information, never removing or attacking any leader. The
   standing footer disclaimer (`base.html.twig`) states this; keep it on every page.
2. **Sourced facts framed as questions. No accusations.** Lead with what members are entitled to
   ask, backed by the public record, not with conclusions about wrongdoing.
3. **Name no private individuals.** Public office-holders acting in public roles may be named from
   the public record where the user has approved it; private individuals never. When migrating
   OIATC content, strip conflict-by-juxtaposition framing and any unverified `(confirm)` claims
   (see `Projects/Sagamok-Accountability/legal/oiatc-content-audit/LEGAL-RISK-AUDIT.md`).
4. **No em dashes (U+2014) anywhere in copy.** Use commas, colons, or new sentences. Enforced by
   `php bin/lint-copy.php` (run before every commit/deploy).

## Brand

Vibrant, member-led, treaty-wide. Derived from the June 2026 one-pager invitation. Distinct from
OIATC (amber) and from the retired green/clay static draft.

- Tokens in `public/css/site.css`: deep indigo-violet primary (`--indigo` #4f2fb0 / `--indigo-deep`
  #38217f), magenta-pink accent (`--magenta` #c41d8f), a full-spectrum **rainbow rule** (`--rainbow`)
  as the signature motif (all 21 nations together), a warm **coral** circle on the hero, on
  near-white paper. Fraunces (serif headings) + Inter (body).
- Voice: "Members and beneficiaries, asking together." / "Prepared by members, for members."

## Rendering pattern

Hand-authored editorial pages, not entity content. The framework SSR package is component/entity
oriented; this app uses a small plain-Twig layer instead:

- `src/Support/View.php` — `View::render($template, $context)` over a `Twig\Environment` +
  `FilesystemLoader('templates')` (the same shape the framework's own SSR tests use).
- `templates/base.html.twig` — shared shell (rainbow rule, masthead nav, standing footer
  disclaimer). Pages live in `templates/pages/**` and `{% extends 'base.html.twig' %}`.
- `src/Controller/SiteController.php` — one thin method per page, returns an HTML `Response`.
- `src/Provider/AppServiceProvider.php` — routes (a name => [path, action] table).
- Add a page = new `templates/pages/*.twig` + a `SiteController` method + a route row.

## Dev server

```bash
php -S 127.0.0.1:8101 -t public public/index.php   # preview profile "rhtcircle"
composer run dev                                   # FrankenPHP (prod-like), 127.0.0.1:8080
```

## Original Waaseyaa scaffold notes

## Architecture

```
src/
├── Access/        Authorization policies
├── Controller/    HTTP controllers (thin orchestration)
├── Domain/        Domain logic grouped by bounded context
├── Entity/        Entity classes (extend ContentEntityBase)
├── Provider/      Service providers (DI, routing, entity registration)
└── Support/       Cross-cutting utilities
```

### Key Patterns

- **Entities** extend `ContentEntityBase` and register via `EntityTypeManager`
- **Persistence** uses `EntityRepository` + `SqlStorageDriver` (see `.claude/rules/waaseyaa-framework.md`)
- **Routes** defined in `ServiceProvider::routes()` via `WaaseyaaRouter`
- **Auth** via `Waaseyaa\Auth\AuthManager` (session-based)
- **Config** via `config/waaseyaa.php` — use `getenv()` or `env()` helper, NEVER `$_ENV`
- **PSR-4 one-class-per-file** — each PHP file declares exactly one class/interface/enum. Namespace matches directory path.

### ServiceProvider DI Methods

Service providers extend `Waaseyaa\Foundation\ServiceProvider\ServiceProvider`. Register bindings in `register()`, use `boot()` for event subscribers and cache warming.

```php
// In register():
$this->singleton(MyInterface::class, fn () => new MyService($this->resolve(Dependency::class)));
$this->bind(TransientService::class, TransientService::class);  // new instance each time
$myService = $this->resolve(MyInterface::class);  // resolve a registered binding
$this->tag(MyInterface::class, 'my_tag');  // tag for grouped resolution
$this->entityType(new EntityType(...));  // register an entity type
```

**Method signatures** (from `Waaseyaa\Foundation\ServiceProvider\ServiceProvider`):

| Method | Signature | Purpose |
|--------|-----------|---------|
| `singleton()` | `protected singleton(string $abstract, string\|callable $concrete): void` | Bind as shared instance (resolved once) |
| `bind()` | `protected bind(string $abstract, string\|callable $concrete): void` | Bind as transient (new instance each call) |
| `resolve()` | `public resolve(string $abstract): mixed` | Resolve a binding (falls back to kernel resolver) |
| `tag()` | `protected tag(string $abstract, string $tag): void` | Tag a binding for grouped resolution |
| `entityType()` | `protected entityType(EntityTypeInterface $entityType): void` | Register an entity type definition |

### Key Framework Namespaces

| Interface | Full Namespace | Purpose |
|-----------|---------------|---------|
| `EntityRepositoryInterface` | `Waaseyaa\Entity\Repository\EntityRepositoryInterface` | Entity CRUD (find, findBy, save, delete, saveMany, deleteMany) |
| `AccessPolicyInterface` | `Waaseyaa\Access\AccessPolicyInterface` | Entity access control (access, createAccess, appliesTo) |
| `FieldAccessPolicyInterface` | `Waaseyaa\Access\FieldAccessPolicyInterface` | Field-level access (open-by-default, Forbidden restricts) |
| `QueueInterface` | `Waaseyaa\Queue\QueueInterface` | Dispatch messages: `dispatch(object $message): void` |
| `Job` | `Waaseyaa\Queue\Job` | Abstract queue job base class |
| `DatabaseInterface` | `Waaseyaa\Database\DatabaseInterface` | Raw SQL via Doctrine DBAL (for non-entity tables) |
| `ServiceProvider` | `Waaseyaa\Foundation\ServiceProvider\ServiceProvider` | Base class for service providers |

### Queue Job Pattern

```php
use Waaseyaa\Queue\Job;
use Waaseyaa\Queue\QueueInterface;

final class SendWelcomeEmail extends Job
{
    public int $tries = 3;        // max attempts
    public int $timeout = 30;     // seconds before timeout
    public int $retryAfter = 10;  // seconds between retries

    public function __construct(private readonly string $userId) {}

    public function handle(): void
    {
        // Job logic here
    }

    public function failed(\Throwable $e): void
    {
        // Cleanup on final failure (optional override)
    }
}

// Dispatch via QueueInterface:
$queue->dispatch(new SendWelcomeEmail($userId));
```

## Orchestration Table

<!-- Map file patterns to skills and specs as you add them -->
| File Pattern | Skill | Spec |
|-------------|-------|------|
| `src/Entity/**` | `waaseyaa:entity-system` | entity-system.md |
| `src/Access/**` | `waaseyaa:access-control` | access-control.md |
| `src/Provider/**` | `feature-dev` | — |
| `.claude/rules/**` | `waaseyaa:spec-maintenance` | — |
| `docs/specs/**` | `waaseyaa:spec-maintenance` | — |

<!-- Note: waaseyaa:* skills are placeholders. They will not function
     until the skills are built. The entries document intended routing. -->

## Specs and Spec Kitty

Framework subsystem specs ship in the `waaseyaa/framework` repo under `docs/specs/`. Read them from checkout or upstream; there is no bundled Node spec MCP in the framework.

This repository may adopt **[Spec Kitty](https://github.com/Priivacy-ai/spec-kitty)** for structured spec/plan/task workflows (see framework `CLAUDE.md`). Framework governance is **Spec Kitty–first**; GitHub is PR/CI and optional issues per `docs/specs/workflow.md`.

## Development

```bash
composer install                    # Install dependencies
php -S localhost:8080 -t public     # Dev server
./vendor/bin/phpunit                # Run tests
./vendor/bin/waaseyaa               # CLI
bin/maintenance/waaseyaa-version    # Framework provenance (path SHA, lockfile, drift vs golden)
bin/maintenance/waaseyaa-audit-site # Mechanical convergence preflight (validate + bins + provenance)
./vendor/bin/waaseyaa sync-rules    # Update framework rules from Waaseyaa
```

Set `WAASEYAA_GOLDEN_SHA` or add `.waaseyaa-golden-sha` for CI drift gates (see `docs/specs/version-provenance.md` in the framework repo).

**Per-site convergence audits:** follow [per-site-convergence-audit.md](https://github.com/waaseyaa/framework/blob/main/docs/specs/per-site-convergence-audit.md) in the Waaseyaa monorepo; record findings under `docs/audits/` per that spec.

## Agent context

| Layer | Location | Purpose |
|------|----------|---------|
| **Constitution** | `CLAUDE.md` (this file) | Architecture, conventions, orchestration |
| **Rules** | `.claude/rules/waaseyaa-*.md` | Framework invariants (always active, never cited) |
| **Specs** | `docs/specs/*.md` | Domain contracts — read from disk; optional Spec Kitty in framework repo |

Framework rules are owned by Waaseyaa. Update them via `./vendor/bin/waaseyaa sync-rules` after `composer update`.

When modifying a subsystem, update its spec in the same PR.

## Known Gaps

<!-- Track technical debt and migration items here -->

## Gotchas

- **Never use `$_ENV`** — Waaseyaa's `EnvLoader` only populates `putenv()`/`getenv()`. Use `getenv()` or the `env()` helper.
- **SQLite write access** — Both the `.sqlite` file AND its parent directory need write permissions for WAL/journal files.
