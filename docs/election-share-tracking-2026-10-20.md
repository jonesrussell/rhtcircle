# Election sharing and contact conversions

The final-week Facebook draft uses this registered link:

https://rhtcircle.ca/elections/sables-spanish-rivers-2026?utm_source=facebook&utm_medium=social&utm_campaign=ssr-election-2026&utm_content=final-week

`CampaignRegistry` owns the exact labels and landing route. Unknown, duplicate or partially supplied tags do not create a campaign. Arbitrary query parameters, including Facebook click IDs, are never stored by this feature. The canonical URL stays untagged.

The existing first-party browser script keeps only a registered campaign key and its 30-minute expiry in same-tab session storage. There is no new cookie or visitor identifier. Another tagged campaign replaces it; invalid explicit campaign tags clear it. Blocked session storage degrades to in-page attribution. Client DNT/GPC disables tracking and clears attribution; request DNT/Sec-GPC headers also prevent collection. These are measurement limits, not a complete count of readership.

## Persistence and reporting

The existing Waaseyaa `DatabaseInterface` and schema APIs create an additive `analytics_campaign_event` operational table through `app:initialize`. No source table or historical value is modified. Page requests do not initialize schemas. A registered landing writes one event per hashed random pageview ID; repeated delivery of that pageview is deduplicated.

A successful `/api/contact` request stores the contact and its conversion in the same framework transaction. No conversion is counted for validation failure, rate limiting, honeypots or privacy opt-outs. The public collector cannot submit a conversion event. Each stored message is one conversion, not one person or an authenticated candidate submission. The form blocks concurrent double clicks; separate successful submissions remain separate messages. Analytics storage failure rolls back that tracked contact save, so the form returns failure rather than silently losing a conversion or reporting success for an unsaved message.

The conversion event holds only its type, a known campaign key or null, a random event key and the timestamp. No name, address, message, contact row ID, visitor hash or user-agent enters the conversion table. Unknown campaign keys become unattributed saved messages. Attribution supplied by the client is a measurement hint, not proof that someone saw an original Facebook post. Reshares keep the same tag.

The existing gated Anokii analytics page shows tagged guide landings, tracked saved messages, and saved messages without campaign attribution in the selected UTC date range. There is no historical backfill. `ssr26-release-check` is a separate known test campaign, excluded from campaign/conversion totals. Its verification link uses source `internal`, medium `verification`, campaign `ssr-election-2026`, content `release-check`. General site pageview totals may still include verification traffic. No email notification, Meta Pixel, Meta CAPI or Facebook publishing was added.

## Framework decision

Reviewed framework analytics/audit specifications and installed database/schema interfaces, the Minoo consumer at `C:/projects/minoo-codex`, and repository code searches for `analytics` and `utm_` in `jonesrussell/claudriel`. No matching campaign-attribution component was found. Framework audit events concern governed operational actions, not anonymous marketing measurements. This extends the Circle's existing analytics and contact repositories through framework APIs, without vendor changes or another database engine.

Reusable campaign measurement is a framework candidate if a second consumer adopts it. Owner: framework/app maintainers. Reproduction: a registered share link followed by a successful contact submission. Acceptance boundaries: privacy opt-outs, finite label registry, deduplicated landings, server-only conversions, atomic persistence, additive setup, and private reporting. This app release does not introduce a framework package.

## Share image

`public/images/og/pages-election-2026.png` is the authored 1730 by 909 PNG. SiteRenderer selects it by the existing per-template convention and adds a content version to its URL. Open Graph and Twitter metadata use the shared template, with the correct size and descriptive alt text supplied by the election page. The batch OG generator explicitly preserves this authored asset.

Created using the built-in image-generation tool with this design prompt: a flat, non-partisan RHT Circle election guide in the existing indigo/magenta palette, large Sables-Spanish Rivers title, campaign statements/Council records/meeting recordings/voting information, community names, and a full-height date field showing October 26, 2026 at 8 pm Eastern. No portraits, rankings, government seals, or fingernail cards. Exact prompt and source image provenance are retained in the separate election release directory.

Publication and actual runtime checks are recorded in `E:/Projects/Massey-Election-2026/releases/election-share-tracking-2026-10-20/`. This file describes the implementation; it is not itself proof of deployment.
