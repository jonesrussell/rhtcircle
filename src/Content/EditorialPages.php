<?php

declare(strict_types=1);

namespace App\Content;

/** Canonical route-to-template map for static editorial pages. */
final class EditorialPages
{
    /** @return array<string, array{string, string}> */
    public static function routes(): array
    {
        return [
            // Member-run live stream of the July 23, 2026 Sagamok members'
            // meeting. Top-level /live so the URL can be said out loud in the
            // room; the Twitch channel embed is permanent, so the page works
            // before, during, and after the broadcast.
            'live' => ['/live', 'pages/live.html.twig'],

            // The Treaty: orientation pillar. The four-part annuity explainer and
            // its distribution-models companion migrated here from /treaty-wide
            // (301s below); fixed-content pages, no context needed.
            'treaty' => ['/treaty', 'pages/treaty/index.html.twig'],
            'treaty-distribution-models' => ['/treaty/distribution-models', 'pages/treaty/distribution-models.html.twig'],
            // (/treaty/language is registered explicitly below: it renders a
            // server-side Anishinaabemowin lookup against Minoo's language API.)
            'treaty-settlement' => ['/treaty/settlement-where-it-goes', 'pages/treaty/settlement-where-it-goes.html.twig'],

            // (/myth-versus-record is registered explicitly below: it renders from
            // the managed myth_entry content type, not a static template.)

            // Original member-led reporting is managed node/article content.
            // /news and /news/{slug} are registered explicitly below.

            // Transparency: the settlement asks and the shared standard.
            'treaty-wide' => ['/treaty-wide', 'pages/treaty-wide.html.twig'],
            'standard' => ['/standard', 'pages/standard.html.twig'],
            'records-request' => ['/standard/records-request', 'pages/standard/records-request.html.twig'],

            'land' => ['/land', 'pages/land/index.html.twig'],
            'land-massey' => ['/land/massey-solar-project', 'pages/land/massey-solar-project.html.twig'],
            'land-massey-what-youve-heard' => ['/land/massey-solar-project/what-youve-heard', 'pages/land/massey-solar-project/what-youve-heard.html.twig'],
            'land-massey-voices' => ['/land/massey-solar-project/voices', 'pages/land/massey-solar-project/voices.html.twig'],
            'land-massey-climate' => ['/land/massey-solar-project/climate', 'pages/land/massey-solar-project/climate.html.twig'],

            // Community safety: its own section. Sensitive pages carry a crisis-line
            // strip and a Quick Exit button; the hate-and-extremism page moved here
            // from /land/territory-and-safety (301 below).
            'safety' => ['/safety', 'pages/safety/index.html.twig'],
            'safety-get-help-now' => ['/safety/get-help-now', 'pages/safety/get-help-now.html.twig'],
            'safety-emergency-preparedness' => ['/safety/emergency-preparedness', 'pages/safety/emergency-preparedness.html.twig'],
            'safety-missing-persons-and-mmiwg' => ['/safety/missing-persons-and-mmiwg', 'pages/safety/missing-persons-and-mmiwg.html.twig'],
            'safety-harm-reduction' => ['/safety/harm-reduction', 'pages/safety/harm-reduction.html.twig'],
            'safety-protecting-elders' => ['/safety/protecting-elders', 'pages/safety/protecting-elders.html.twig'],
            'safety-information-safety' => ['/safety/information-safety', 'pages/safety/information-safety.html.twig'],
            'safety-hate-and-extremism' => ['/safety/hate-and-extremism', 'pages/safety/hate-and-extremism.html.twig'],

            // Resources: the member-facing get-help directory (the 8th section).
            // /resources itself is registered explicitly below (graph-driven), not
            // here; this is its child page.
            'resources-paying-for-school' => ['/resources/paying-for-school', 'pages/resources/paying-for-school.html.twig'],

            // The Circle: the member-led movement. About: what the hub is and is not.
            'circle' => ['/circle', 'pages/circle/index.html.twig'],
            'about' => ['/about', 'pages/about.html.twig'],
            'get-involved' => ['/get-involved', 'pages/get-involved.html.twig'],

            // sagamok-awaiting-council is registered explicitly below (not
            // here): it needs the live signature count passed into the
            // template, which this generic no-context loop cannot supply.
            // Support images: a client-side canvas generator (Facebook cover,
            // square post, profile badge) for the records request. No login,
            // no upload, no names collected. Ported from main, where it was
            // built directly during the bad-pin window; see the awaiting-
            // council reconciliation commit for context.
            'sagamok-support-images' => ['/communities/sagamok/support-images', 'pages/communities/sagamok/support-images.html.twig'],
            'sagamok-booklets' => ['/communities/sagamok/booklets', 'pages/communities/sagamok/booklets.html.twig'],
            'sagamok-members-first-plan' => ['/communities/sagamok/members-first-plan', 'pages/communities/sagamok/members-first-plan.html.twig'],
            'sagamok-members-first-plan-government' => ['/communities/sagamok/members-first-plan/member-government', 'pages/communities/sagamok/members-first-plan/member-government.html.twig'],
            'sagamok-members-first-plan-wealth' => ['/communities/sagamok/members-first-plan/community-wealth', 'pages/communities/sagamok/members-first-plan/community-wealth.html.twig'],
            'sagamok-members-first-plan-enterprises' => ['/communities/sagamok/members-first-plan/enterprises', 'pages/communities/sagamok/members-first-plan/enterprises.html.twig'],
            'sagamok-members-first-plan-homes' => ['/communities/sagamok/members-first-plan/homes-infrastructure', 'pages/communities/sagamok/members-first-plan/homes-infrastructure.html.twig'],
            'sagamok-members-first-plan-health' => ['/communities/sagamok/members-first-plan/health-families-elders', 'pages/communities/sagamok/members-first-plan/health-families-elders.html.twig'],
            'sagamok-members-first-plan-culture' => ['/communities/sagamok/members-first-plan/language-culture-learning', 'pages/communities/sagamok/members-first-plan/language-culture-learning.html.twig'],
            'sagamok-members-first-plan-lands' => ['/communities/sagamok/members-first-plan/lands-safety-rights', 'pages/communities/sagamok/members-first-plan/lands-safety-rights.html.twig'],
            'sagamok-members-first-plan-service' => ['/communities/sagamok/members-first-plan/public-service', 'pages/communities/sagamok/members-first-plan/public-service.html.twig'],
            'sagamok-members-first-plan-implementation' => ['/communities/sagamok/members-first-plan/implementation', 'pages/communities/sagamok/members-first-plan/implementation.html.twig'],
            'sagamok-members-first-plan-scorecard' => ['/communities/sagamok/members-first-plan/scorecard', 'pages/communities/sagamok/members-first-plan/scorecard.html.twig'],
            'sagamok-members-first-plan-sources' => ['/communities/sagamok/members-first-plan/source-record', 'pages/communities/sagamok/members-first-plan/source-record.html.twig'],
            'sagamok-how-organized' => ['/communities/sagamok/how-its-organized', 'pages/communities/sagamok/how-its-organized.html.twig'],
            'sagamok-members-website-issue' => ['/communities/sagamok/members-website-issue', 'pages/communities/sagamok/members-website-issue.html.twig'],
            'sagamok-where-your-data-lives' => ['/communities/sagamok/where-your-data-lives', 'pages/communities/sagamok/where-your-data-lives.html.twig'],
            'sagamok-long-term-care' => ['/communities/sagamok/long-term-care', 'pages/communities/sagamok/long-term-care.html.twig'],
            'sagamok-gr-truss' => ['/communities/sagamok/gr-truss', 'pages/communities/sagamok/gr-truss.html.twig'],
            'sagamok-play-limited-partnership' => ['/communities/sagamok/play-limited-partnership', 'pages/communities/sagamok/play-limited-partnership.html.twig'],
            'sagamok-espanola-mill-bmi' => ['/communities/sagamok/espanola-mill-bmi', 'pages/communities/sagamok/espanola-mill-bmi.html.twig'],
            'sagamok-one-seat-one-salary' => ['/communities/sagamok/one-seat-one-salary', 'pages/communities/sagamok/one-seat-one-salary.html.twig'],
            'sagamok-member-election-law' => ['/communities/sagamok/member-election-law', 'pages/communities/sagamok/member-election-law.html.twig'],
            // Client-side member letter builder. Personal text stays in the
            // browser: the app receives no form submission and stores nothing.
            'sagamok-write-to-council' => ['/communities/sagamok/write-to-council', 'pages/communities/sagamok/write-to-council.html.twig'],
            // The member accountability resolution is registered explicitly
            // below so its
            // generated, source-backed resolution data reaches the template.
            // The Conflict Register: an interactive tool, filterable by
            // councillor or company, cross-referencing enterprise money
            // votes against councillor-director board seats. First of a
            // planned interactive data hub for this section (see the
            // "companion tools" note in the build history for this page).
            'sagamok-conflict-register' => ['/communities/sagamok/conflict-register', 'pages/communities/sagamok/conflict-register.html.twig'],
            // A member's record (Russell Jones): the members-only portal
            // exposure, its capture in the public Internet Archive, and what
            // is being asked of Council. Companion to members-website-issue.
            'sagamok-it-accountability' => ['/communities/sagamok/it-accountability', 'pages/communities/sagamok/it-accountability.html.twig'],
            // Public-records kit: eleven member-compiled cards (image + ready
            // caption), built from public sources only, for members to copy
            // and post themselves.
            'sagamok-share' => ['/communities/sagamok/share', 'pages/communities/sagamok/share.html.twig'],

            // Community life: events shared across the treaty nations. The youth
            // baseball league spans Sagamok, Serpent River, and Atikameksheng and
            // is featured from each of their community pages.
            'community-life-baseball' => ['/community-life/indigenous-baseball-league', 'pages/community-life/indigenous-baseball-league.html.twig'],
        ];
    }
}
