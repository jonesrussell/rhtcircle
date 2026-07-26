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

## News workflow

To publish another investigation:

1. Add a page under `templates/pages/news/`.
2. Extend `layouts/news_article.html.twig`.
3. Set the article metadata object and override `article_body`,
   `article_sidebar`, and `article_sources`.
4. Add its route and machine-readable index entry.
5. Add it to the News index through a reusable component, not copied markup.
6. Run PHPUnit, the copy lints, a dry-run ingest, and responsive browser checks.
