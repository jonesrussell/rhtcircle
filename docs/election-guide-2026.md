# Sables-Spanish Rivers election guide

Draft route: `/elections/sables-spanish-rivers-2026`.

The editorial page uses the existing SiteController/SiteRenderer, canonical EditorialPages map, shared base template and CSS. The same template is on the corpus ingestion allowlist. No entity schema, permissions, campaign consent, public chat or scheduler settings were changed.

Public source research is held separately at `E:/Projects/Massey-Election-2026`. Its source catalogue has 65 hash-verified captures. The page contains the 15 certified municipal candidates, selected recorded votes, municipal issue baselines, voting information, a common seven-question invitation and explicit research limitations. Candidate platforms have not yet been authenticated or added. No direct invitations have been sent.

The existing `/contact` form is the intake route. It stores submissions privately in the gated admin inbox, with no email notification currently wired. The page does not promise automatic publication or response notifications. Before publishing submitted material, verify candidate identity and publication permission, retain the original privately, and label and date the candidate's statement. Do not publish private contact details.

Local review: the route renders with one H1 and 15 profiles at 360, 768, 1024 and 1440 pixels without document overflow. Profile disclosure and the questionnaire jump link work. The contact link points to the existing form. Corpus dry run succeeds. The existing favicon request gives a local 404. Composer validation, PHPStan, both copy lints and all 352 tests (2,547 assertions) pass. Four existing native Windows SQLite-cleanup warnings were reproduced in the four affected tests; they concern temporary database unlinking, not failed assertions. Full logs are retained in the separate research workspace. A fresh local field-access preflight reports ready; no schema/classification changed, so main's production artifact is retained.

No deployment has been performed. Publication remains a separate decision. A release must follow `release-governance.md`, including reconciliation against current main and Linux/production-snapshot qualification. After deployment, verify the live page and contact inbox workflow before distributing the invitation. The residents-group post remains an unsent draft.
