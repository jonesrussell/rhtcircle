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

## News workflow

To publish another investigation:

1. Add a page under `templates/pages/news/`.
2. Extend `layouts/news_article.html.twig`.
3. Set the article metadata object and override `article_body`,
   `article_sidebar`, and `article_sources`.
4. Add its route and machine-readable index entry.
5. Add it to the News index through a reusable component, not copied markup.
6. Push the page normally. The OG-card workflow discovers templates through
   inherited layouts and generates a 1200 x 630 card from `og_title` and
   `og_description`, falling back to `title` and `description`.
7. Run PHPUnit, the copy lints, a dry-run ingest, and responsive browser checks.

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
