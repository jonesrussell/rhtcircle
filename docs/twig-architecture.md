# Twig architecture

RHT Circle renders application pages through Waaseyaa's framework-owned Twig
environment. The application does not construct a second Twig environment and
does not use a static rendering facade.

## Rendering boundary

- `RenderingServiceProvider` registers `SiteRenderer` and
  `PublicAssetVersioner` as application services.
- `SiteRenderer` receives Waaseyaa's `Twig\Environment` through the service
  container, supplies common page context, and returns HTML responses.
- `TwigConfigurator` adds only the application helpers Waaseyaa does not own:
  `current_url()`, `myth()`, `last_updated()`, the shared asset version, and the
  Anokii admin template paths.
- Controllers receive `SiteRenderer` as a constructor dependency.

## Template hierarchy

- `templates/base.html.twig` owns the document, metadata, site header,
  navigation, main container, and footer.
- `templates/layouts/` contains section or content-type layouts. Long-form news
  stories extend `layouts/news_article.html.twig`.
- `templates/pages/` contains page content and page-specific metadata.
- `templates/components/` contains reusable presentation contracts. News uses
  `news_feature.html.twig` and `news_card.html.twig`.
- Includes pass explicit context and use `only` whenever the component does not
  need inherited page state.

## Presentation rules

- New pages and components do not include inline `<style>` blocks.
- Shared CSS lives in `public/css/site.css` and uses the site tokens.
- `PublicAssetVersioner` hashes the shared CSS and JavaScript files so changed
  assets receive a new cache key automatically.
- Template-owned images declare width and height. Below-the-fold images use
  lazy loading.
- Layouts must have no horizontal overflow at 360, 768, 1024, and 1440 CSS
  pixels.

## Field-read boundary

Waaseyaa alpha.274 fails closed for entity fields that do not declare a read
classification. The application-owned classification document is
`.waaseyaa/field-access-classification.json`.

- Public Anokii graph and document-chunk fields are explicitly classified
  `public` because they contain the same published site content exposed by the
  public search and chat surfaces.
- Operational audit, pipeline, and trace labels remain `internal`.
- Run `APP_ENV=local vendor/bin/waaseyaa field-access:preflight
  --write-artifact` after changing entity models, classifications, or framework
  packages. Commit `.waaseyaa/field-access-preflight.json` with the change.
  Production checks its checksum, framework identity, and schema fingerprint
  before booting. A deploy is ready only when the report has zero unclassified
  entries and `ready` is `true`.

## News and community workflow

Managed articles are revisionable `node/article` entities. `ArticleRepository` resolves public collections through Waaseyaa Listing and filters before access-aware pagination. An empty or non-Nation `community_slug` is grouped as Treaty-wide scope; legacy `circle` tags do not become broken community links.

`PublicationContext` supplies the same homepage, news and community context to HTTP controllers and `app:ingest`. `EditorialPages` owns the static route-to-template catalogue. The ingest allowlist is intentionally narrower: not every interactive or advocacy page belongs in the retrieval corpus.

- News covers all 21 Nations. The homepage selects a dated mix with at most one story connected to each Nation, and one Treaty-wide story, in its six-card selection.
- The news index has Nation and topic filters, explicit coverage gaps and pagination for original reporting. Source summaries retain their actual dates and last-review date.
- Each community page links to its filtered news and official communications. A profile is not proof of an active newsroom in that community.
- Sagamok reporting, open questions, member proposals and tools are separate collapsed collections. Proposals do not become newsroom conclusions or adopted Council policy.
- For existing stories, CMS content and revisions are authoritative. Hand-authored source templates are migration inputs. Do not edit a source template and assume an existing CMS article changed.
- `app:cms-migrate-articles` is an explicit migration operation. Its named historical refresh lists can overwrite selected article fields; review those lists before invoking it against an existing content database.
- Run `composer check`, dry-run ingestion and responsive browser checks for publication changes. Production publication and deployment are separate actions.

## App-owned schema lifecycle

`app:initialize` runs app schema setup. `app:seed-member-tools` seeds legacy polls and campaign definitions explicitly. HTTP boot does neither. Both public controllers and ingestion use the kernel's database service, not their own SQLite path resolution.

## Social images

Every static page that ultimately inherits `base.html.twig` receives a
generated social image under `public/images/og/`. The generator reruns after
page edits, so changing a page headline or social description also refreshes
the card. `SiteRenderer` selects the matching card automatically and uses the
site card only while a page-specific image is unavailable.

Editors can override the generated card without changing the site-wide
behavior:

- Set `social_image`, `social_image_alt`, `social_image_width`, and
  `social_image_height` in the page or article editing data.
- Supply `og_image_override` in the page render context for an editor-selected
  image.
- For a permanently bespoke template, override Twig's `og_image` block.
- For a designed card generated from its own HTML, register the page in
  `scripts/generate-og.js` under `overrides`.

The same resolved image is emitted for Open Graph and the large Twitter/X card.
