# Local development

## Canonical location and dependencies

Use `C:\dev\rhtcircle`. Sagamok's private research remains on E:. Install published dependencies from the committed lock with `composer install`. `composer.local.json` is not loaded; the merge plugin has been removed. Its old example is pending deletion and is not supported guidance.

PHP 8.5 with PDO SQLite, SQLite3 and mbstring is required. Run `composer check-platform-reqs`. Do not borrow another checkout's vendor tree.

## Local environment

`php bin/post-create-setup.php` creates `.env` only when absent, with independent random JWT and `base64:` master secrets. It never changes an existing environment. `.env`, SQLite databases, caches and logs are ignored. Never copy production credentials into a local environment or print secrets.

For the built-in server:

```powershell
php -S 127.0.0.1:8101 -t public public/index.php
```

Use `composer dev` for the supported FrankenPHP runtime. The built-in server is sufficient for editorial previews; it does not qualify concurrent SSE or worker behaviour.

## Database lifecycle

For a fresh database, or a local production snapshot undergoing a framework upgrade:

1. `php vendor/bin/waaseyaa schema:sync --dry-run`, then `schema:sync` after reviewing the additive changes.
2. `php vendor/bin/waaseyaa db:init` to apply framework migrations.
3. For a database without activated canonical configuration, `php vendor/bin/waaseyaa install:init`.
4. `php vendor/bin/waaseyaa app:initialize` for app-owned schemas. It does not seed campaigns.
5. For pre-authority entity rows after an upgrade, `php vendor/bin/waaseyaa entity:backfill-mutation-authorities --reason="Local framework upgrade" --json`.
6. `php vendor/bin/waaseyaa optimize:manifest`.
7. Run `field-access:preflight --write-artifact` and inspect readiness. An HTTP 200 in local mode is not production readiness.

The alpha.305 refresh requires schema synchronization before `db:init` for legacy entity tables. The unused development-only AI-agent dependency was removed. Field-read preflight is ready after removing the stale app declaration for the framework-owned pipeline label; an integration assertion verifies anonymous pipeline access remains denied.

`app:seed-member-tools` is a separate, explicit legacy setup command. It writes historic Sagamok polls and campaign definitions and aggregate counts. Do not run it during ordinary development or a production refresh. Existing signatures stay attached to their original consent instrument.

## Checks and agent setup

```powershell
composer check
php vendor/bin/waaseyaa app:ingest --dry-run
composer agents:install
php vendor/bin/waaseyaa graph:dump --strict --section=public_surface
```

Bimaaji is included by the framework. Installation is idempotent and tracks owned files in `.waaseyaa/bimaaji-install.json`. Add app guidance outside its markers; never edit generated framework guidance to mask a dependency bug. Strict graph export currently fails on the legacy framework Debug route contributor and remains an explicitly recorded diagnostic gap. No remote MCP credentials or new public permissions were added. GitHub main and direct release checks are governed by `docs/release-governance.md`.

The legacy `bin/maintenance/waaseyaa-audit-site` shell helper requires Bash and a byte-identical skeleton front controller. It does not qualify this app's runtime adapter on native Windows. `composer audit-site` now runs portable app checks and a dry-run corpus render. Use `composer check` for the core app checks and the focused framework commands above; see the audit for this tooling gap.
