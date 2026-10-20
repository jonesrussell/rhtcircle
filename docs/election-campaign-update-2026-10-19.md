# Election guide: campaign-source update

The guide previously listed all fifteen certified candidates but had no current
campaign material. This update adds short, dated summaries with 22 original
campaign links and four meeting recordings. Profiles open individually; no
comparison tool, score or ranking is introduced. Native disclosures, a two-column
desktop list and a dated recording list make the additional material easier to
scan on desktop and mobile.

The candidate roster, existing selected roll calls and current voting-help
corrections remain. A newer Township clinic-MOU announcement is attributed
separately from candidate claims of credit. Missing written statements, incomplete
video review, and the Walford publisher's missing-audio notice remain explicit.
Automatic transcripts, unverified financial totals, legal assertions, resident
allegations and private personal circumstances are not published.

The site owner authorized this live update on October 19, 2026. This authority covers the
election page, not Facebook posting or direct candidate messages. The public page
does not imply any candidate submitted material directly to the Circle.

## Architecture

This remains an editorial page in the existing Waaseyaa Twig environment and
page/corpus catalogue. It inherits `base.html.twig`; styles use existing tokens
in `public/css/site.css`. Framework SSR composition and existing app disclosures
were checked. No renderer, database, route, JavaScript engine, dependency or
framework API was added or replaced. No source captures enter served directories.

GitHub main owns the published template. Local private research and release proof
are at `E:/Projects/Massey-Election-2026/`, with this release under
`releases/campaign-sources-2026-10-19/`. The original dossier/PDF are the initial
publication snapshot, not a current export of this updated page.

## Verification

All fifteen disclosures open and close. Each has a verified-record section and
an explicit information gap. Browser checks at 360, 768, 1024 and 1440 pixels show
one H1, fifteen profiles, four recordings and no page-level horizontal overflow.
Desktop and mobile renders were visually reviewed. Copy lints, PHPStan, corpus
dry-run rendering and native field preflight pass.

Native preflight differs from the committed artifact only because the Windows
checkout uses CRLF for composer.lock. Normalized lock contents are identical; the
native result is retained with release evidence. The committed Linux artifact
and dependency lock are unchanged. Full exact-commit Linux qualification,
production-snapshot rehearsal and live acceptance run through the existing
single-target promotion process. Their completed results belong in the release
record, not a claim inferred from local rendering.
