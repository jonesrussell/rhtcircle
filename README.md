# RHT Circle

Independent, member-led news and practical resources across the 21 Robinson Huron Treaty Nations. This is a Waaseyaa application, not the framework skeleton or an official Nation website.

## Start here

- [Local setup](docs/local-dev.md): PHP, Composer, database lifecycle and preview.
- [Contributor contract](AGENTS.md): editorial boundaries, architecture and checks.
- [Site architecture](docs/twig-architecture.md): rendering and managed news.
- [Local audit and framework friction](docs/audits/site-code-audit-2026-10-05.md): findings, repairs, reproductions and outstanding work.
- [Content decisions](docs/content-audit-2026-10-05.md): retain, combine, relocate and retire decisions.

## Quick start

```powershell
composer install
php bin/post-create-setup.php
php vendor/bin/waaseyaa schema:sync
php vendor/bin/waaseyaa db:init
php vendor/bin/waaseyaa install:init
php vendor/bin/waaseyaa app:initialize
php vendor/bin/waaseyaa bimaaji:install --client=codex,claude --no-interaction
php -S 127.0.0.1:8101 -t public public/index.php
```

Use an existing initialized database when available. `install:init` is for a fresh configuration genesis; do not repeat it on an activated site. Schema setup does not seed campaigns or transfer signatures. The legacy member-tool seed command is an explicit operator action, not part of routine startup.

```powershell
composer check
php vendor/bin/waaseyaa app:ingest --dry-run
```

The committed Composer lock is the dependency authority. Framework alpha.305 is the current local baseline. Strict Bimaaji graph export and field-read activation have documented blockers; this local baseline is not ready for production deployment. No dependency symlinks, donor vendor directories or local Composer override files.

## Repository map

| Path | Responsibility |
| --- | --- |
| `src/Content/` | Nation profiles, reviewed source digest, editorial context and page catalogue |
| `src/Cms/` | Managed article fields, seed sources and read model |
| `src/Controller/` | HTTP orchestration and form-specific validation |
| `src/Provider/` | Framework integration, service registration and routes |
| `templates/` | Public shell, components and editorial pages |
| `public/` | Served assets and intentionally public downloads |
| `tests/` | Unit and database-backed integration checks |
| `docs/` | Architecture, local operations, audits and decisions |
| `.agents/skills/`, `.claude/skills/` | Bimaaji-managed framework guidance |
| `storage/`, `var/` | Ignored runtime state and temporary development artifacts |

Private Sagamok research stays in `E:\Projects\Sagamok-Accountability`. Public code and curated public content stay here. Do not import private correspondence or complainant details into served files, graph data, logs or generated skills.
