# Anonymous exposure audit: `myth_entry` and `source_link`

**Date:** 2026-07-30
**Framework:** `waaseyaa/framework` v0.1.0-alpha.279
**Verified against:** a production-shaped schema locally **and** the live site
**Conclusion: nothing private, protected, draft or operational is exposed. No fix
is required.**

---

## Why this audit was run

Both app-defined entity types are registered with `group: 'content'`
(`src/Provider/CmsContentServiceProvider.php:53-54`):

```php
$this->entityType(EntityType::fromClass(MythEntry::class, translatable: true, group: 'content'));
$this->entityType(EntityType::fromClass(SourceLink::class, translatable: true, group: 'content'));
```

`group: 'content'` is significant because the kernel unconditionally registers
`PublishedContentAccessPolicy`, which grants anonymous `view` to any
`content`-group entity — simultaneously opening MCP `entity.read`/`entity.search`,
the SSR `/{type}/{id}` catch-all, GraphQL, and `/api/discovery/*`. Several
framework surfaces are generic over *any* registered entity type and need no
opt-in, so registering a type is itself an exposure decision.

That raised a reasonable concern. **Probing shows the concern does not apply to
these two types**, for a specific and load-bearing reason recorded below.

## Result

Every anonymous surface is closed. Probed with **real, existing ids** (these
types use slugs such as `legal-fees`, not integers — an earlier probe with `id=1`
produced a misleading "not found" that merely meant the row was absent).

| Surface | `myth_entry` | `source_link` | Outcome |
|---|---|---|---|
| MCP `entity.read` | `not found` | `not found` | **denied** |
| MCP `entity.search` | `{items: [], count: 0}` | `{items: [], count: 0}` | **denied** |
| MCP `entity.list_revisions` | `not found` | `not found` | **denied** |
| MCP `relationship.traverse` | `{edges: [], count: 0}` | — | no edges |
| JSON:API `GET /api/{type}` | 404 | 404 | **route absent** |
| SSR `GET /{type}/{id}` | 403 | 403 | **denied** |
| `/api/discovery/{hub,cluster,timeline,endpoint}/…` | 404 (all four) | 404 | **denied** |
| GraphQL `{type}List` | `items: []` | `items: []` | **denied** |
| GraphQL `{type}(id:)` | `null` | `null` | **denied** |
| `GET /api/entity-types` | 403 | 403 | **denied** |
| `GET /api`, `/api/openapi.json`, `/.well-known/mcp.json` | no mention | no mention | not enumerated |

The MCP result is `not found` rather than a distinguishable refusal by design:
`EntityReadTool` collapses view-forbidden and absent into a byte-identical
message, which closes the existence oracle. It is a denial, not a missing row —
the rows demonstrably exist and render on the public page.

**Confirmed on the live site** (`https://rhtcircle.ca`, anonymous, read-only):
`/api/myth_entry` and `/api/source_link` → 404; `/myth_entry/legal-fees` → 403;
`/api/discovery/hub/myth_entry/legal-fees` → 404; MCP `entity.read` → `not
found` for both types; GraphQL `mythEntryList` → `items: []`. Meanwhile
`/myth-versus-record` → 200.

## Why they are closed: no `status` field

`PublishedContentAccessPolicy` is the only registered policy that could grant
`view` to these types, and it grants it only when the entity is *published*:

```php
// PublishedContentStatusReader::isPublished()
$status = ($this->values)($entity)['status'] ?? null;
return EntityValues::statusToInt($status) === 1;
```

`EntityValues::statusToInt(null)` returns `0`, so a type with **no `status`
field is never published** by this policy. Neither type has one — verified in the
production schema, not just locally:

```sql
CREATE TABLE myth_entry  (id, uuid, bundle, question, langcode, default_langcode, _data, PRIMARY KEY (id, langcode));
CREATE TABLE source_link (id, uuid, bundle, label,    langcode, default_langcode, _data, PRIMARY KEY (id, langcode));
```

With no other policy registered, `EntityAccessHandler` resolves **Neutral**, and
entity-level access is `isAllowed()` — deny-unless-granted. So every generic
surface denies, while the app's own controller renders the content through its
repository. That is the correct posture, and it is the same shape
`docs/specs/sagamok-monitoring-dashboard.md` §6.2 prescribes for new types.

**This is load-bearing and fragile.** Adding a `status` field to either type, and
setting it to 1, would silently open all five surfaces at once. So would adding a
`view`-granting policy. Either change should be treated as a publication decision,
not a schema tidy-up.

## Is the content itself intentionally public?

Yes. Every stored row is already fully rendered at `/myth-versus-record`:

- **4 of 4** `myth_entry` rows: every `question` appears on the page.
- **5 of 5** `source_link` rows: every `label` **and** every `url` appears.

Field-by-field:

| Type | Field | Rendered | Assessment |
|---|---|---|---|
| `myth_entry` | `question`, `answer`, `record`, `takeaway` | yes | editorial content, written for publication |
| | `slug` | as a component key | stable identifier, non-sensitive |
| | `weight` | as ordering | display order, non-sensitive |
| `source_link` | `label`, `url` | yes | citations to **public** sources |
| | `owner` | no | a slug reference (`myth_entry:legal-fees`), non-sensitive |
| | `weight` | as ordering | display order, non-sensitive |

No field declares a read level, so all default to `Public` — which matches the
content. There is **no personal data, no credential, and no operational secret**
in either type.

**There is no draft concept for these types.** With no `status` field there is no
published/unpublished distinction, so there is no draft row that could leak.
Content reaches these tables only through the idempotent
`app:cms-seed-myths` migration from `App\Content\MythEntries`, which is
publication-ready copy by construction.

The only stored values not shown verbatim on the page are `slug`, `owner`, and
`weight` — keying and ordering metadata. They are not withheld for sensitivity;
they simply have no display role.

## Conclusion

All exposed fields and rows are intentionally public, and in practice the
entities are **not** anonymously reachable through any generic framework surface
at all. No focused fix is opened.

## Follow-ups (not defects)

1. **Treat adding `status` to either type as a publication decision.** It would
   move them from "denied everywhere" to "anonymously readable on five surfaces"
   in one line, with no other code change and no error.
2. **`docs/specs/sagamok-monitoring-dashboard.md` §6.2 already encodes the rule**
   the new monitor entities must follow (never `api: true`, never
   `group: 'content'`, no `status` field, one bespoke listing ability). This audit
   is the empirical evidence that the rule is real.
3. **Re-run this audit whenever an entity type is added**, or when a policy that
   is not scoped by `appliesTo()` is registered. The probe set above is small
   enough to be re-run by hand in a few minutes.
