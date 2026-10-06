# Local content decisions, October 5, 2026

## Editorial structure implemented

RHT Circle is a news and resource home for all 21 Nations. News, community profiles, Treaty information, land/water and practical resources remain the front doors. Sagamok is one community desk. Its accountability collection has separate reporting, open questions, member proposals and tools. The shared newsroom approach appears at `/about#editorial`.

Nation filters and explicit empty states expose coverage gaps. Official communications are attributed to their source. No fresh reporting has been invented and no old source summary has been re-dated as current. Massey Solar stays under land/water; municipal elections can be covered for their relevance to Treaty territory without making the site a single-community campaign.

## Decisions

- **Keep:** all 21 community profiles, reviewed reporting, Treaty explainers, land/water projects, language and practical resources.
- **Combine discovery:** Sagamok's 25 resources are reached through four collapsed purpose-based collections, instead of a wall of equally promoted complaints and campaigns.
- **Relocate in navigation:** member-authored plans, proposed laws, resolutions and statements belong in the labelled member-proposals collection. Direct URLs and original consent records remain intact.
- **Retire:** unsupported local Composer override machinery, stale skeleton operating guidance, obsolete generated browser artifacts and empty dependency directories. Deletion is pending the tool-policy restriction recorded in the code audit.
- **Reconcile before retirement:** `public/local-preview/` and its bespoke draft builders. At least one draft has distinct authored content; age alone is not evidence that it is disposable. These exports are excluded from the Docker context and Git noise, but exclusion is not deletion or a content migration.

## Template inventory

This inventory covers all 72 page templates. Dynamic Nation and project pages share templates; managed articles also have database-owned revisions. No source content was bulk-deleted.

| Template | Decision | Purpose / ownership |
| --- | --- | --- |
| `pages/about.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/circle/index.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/communities/index.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/communities/nation.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/communities/sagamok/account-or-resign.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/accountability.html.twig` | Keep in bounded desk | Sagamok records/explainers, labelled by purpose; not homepage default |
| `pages/communities/sagamok/awaiting-council.html.twig` | Combine discovery | Open questions or member tools in bounded Sagamok desk; retain direct links |
| `pages/communities/sagamok/booklets.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/conflict-register.html.twig` | Keep in bounded desk | Sagamok records/explainers, labelled by purpose; not homepage default |
| `pages/communities/sagamok/espanola-mill-bmi.html.twig` | Keep in bounded desk | Sagamok records/explainers, labelled by purpose; not homepage default |
| `pages/communities/sagamok/gr-truss.html.twig` | Keep in bounded desk | Sagamok records/explainers, labelled by purpose; not homepage default |
| `pages/communities/sagamok/how-its-organized.html.twig` | Keep in bounded desk | Sagamok records/explainers, labelled by purpose; not homepage default |
| `pages/communities/sagamok/it-accountability.html.twig` | Keep in bounded desk | Sagamok records/explainers, labelled by purpose; not homepage default |
| `pages/communities/sagamok/long-term-care.html.twig` | Keep in bounded desk | Sagamok records/explainers, labelled by purpose; not homepage default |
| `pages/communities/sagamok/member-election-law.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan/community-wealth.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan/enterprises.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan/health-families-elders.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan/homes-infrastructure.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan/implementation.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan/lands-safety-rights.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan/language-culture-learning.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan/member-government.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan/public-service.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan/scorecard.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan/source-record.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan-layout.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-first-plan.html.twig` | Relocate in navigation | Member proposal/advocacy; retain authored source and consent boundaries |
| `pages/communities/sagamok/members-website-issue.html.twig` | Keep in bounded desk | Sagamok records/explainers, labelled by purpose; not homepage default |
| `pages/communities/sagamok/one-seat-one-salary.html.twig` | Keep in bounded desk | Sagamok records/explainers, labelled by purpose; not homepage default |
| `pages/communities/sagamok/play-limited-partnership.html.twig` | Keep in bounded desk | Sagamok records/explainers, labelled by purpose; not homepage default |
| `pages/communities/sagamok/poll.html.twig` | Combine discovery | Open questions or member tools in bounded Sagamok desk; retain direct links |
| `pages/communities/sagamok/share.html.twig` | Combine discovery | Open questions or member tools in bounded Sagamok desk; retain direct links |
| `pages/communities/sagamok/support-images.html.twig` | Combine discovery | Open questions or member tools in bounded Sagamok desk; retain direct links |
| `pages/communities/sagamok/what-matters.html.twig` | Combine discovery | Open questions or member tools in bounded Sagamok desk; retain direct links |
| `pages/communities/sagamok/where-your-data-lives.html.twig` | Keep in bounded desk | Sagamok records/explainers, labelled by purpose; not homepage default |
| `pages/communities/sagamok/write-to-council.html.twig` | Combine discovery | Open questions or member tools in bounded Sagamok desk; retain direct links |
| `pages/community-life/indigenous-baseball-league.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/contact.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/get-involved.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/home.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/land/index.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/land/massey-solar-project/climate.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/land/massey-solar-project/voices.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/land/massey-solar-project/what-youve-heard.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/land/massey-solar-project.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/land/project.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/live.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/myth-versus-record.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/news/article.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/news/index.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/resources/index.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/resources/paying-for-school.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/review/aging-well-starts-at-home-2026-07-26.html.twig` | Keep unlisted | Editorial review source or article migration input; no main navigation |
| `pages/review/gr-truss-investigation-2026-07-25.html.twig` | Keep unlisted | Editorial review source or article migration input; no main navigation |
| `pages/safety/emergency-preparedness.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/safety/get-help-now.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/safety/harm-reduction.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/safety/hate-and-extremism.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/safety/index.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/safety/information-safety.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/safety/missing-persons-and-mmiwg.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/safety/protecting-elders.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/signup-removed.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/signup.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/standard/records-request.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/standard.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/treaty/distribution-models.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/treaty/index.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/treaty/language.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/treaty/settlement-where-it-goes.html.twig` | Keep | Treaty-wide resource or public section page |
| `pages/treaty-wide.html.twig` | Keep | Treaty-wide resource or public section page |
