<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\CommunityHub;
use App\Content\LandProjects;
use App\Content\Nations;
use App\Content\NewsFeed;
use App\Rendering\SiteRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Editorial pages for the public site. Thin: render a Twig template (the route
 * table in AppServiceProvider maps each path to a template) and return HTML.
 */
final class SiteController
{
    public function __construct(private readonly SiteRenderer $renderer) {}

    public function page(string $template): Response
    {
        return $this->renderer->html($template);
    }

    /**
     * Render an unlisted editorial review page and keep it out of search indexes.
     */
    public function reviewPage(string $template): Response
    {
        $response = $this->renderer->html($template);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

        return $response;
    }

    /**
     * Publication front page with current reporting and the 21 community desks.
     */
    public function home(): Response
    {
        $nationNames = [];
        foreach (Nations::all() as $nation) {
            $nationNames[(string) $nation['slug']] = (string) $nation['name'];
        }

        return $this->renderer->html('pages/home.html.twig', [
            'stories' => NewsFeed::recentExternalStories(),
            'regions' => Nations::regions(),
            'communities_by_region' => Nations::byRegion(),
            'nation_names' => $nationNames,
        ]);
    }

    /**
     * Original RHT Circle reporting and a hand-reviewed external news digest.
     */
    public function newsIndex(): Response
    {
        $nationNames = [];
        foreach (Nations::all() as $nation) {
            $nationNames[(string) $nation['slug']] = (string) $nation['name'];
        }

        return $this->renderer->html('pages/news/index.html.twig', [
            'stories' => NewsFeed::recentExternalStories(),
            'regions' => Nations::regions(),
            'communities_by_region' => Nations::byRegion(),
            'nation_names' => $nationNames,
        ]);
    }

    /**
     * The /communities index: the 21 nations grouped by sub-region. Built from
     * the Nations content layer so the index and the per-nation pages stay in
     * sync from one source.
     */
    public function communitiesIndex(): Response
    {
        return $this->renderer->html('pages/communities/index.html.twig', [
            'regions' => Nations::regions(),
            'byRegion' => Nations::byRegion(),
        ]);
    }

    /**
     * A per-nation community page. All 21 nations render through one data-driven
     * hub template: a wide two-column layout with a profile sidebar, the treaty
     * history, and two card grids (member transparency resources, on the
     * territory). Nations with no transparency work yet show an invitation card
     * instead, and the territory grid is built from the land projects that touch
     * the nation. The unofficial banner and correction link are carried by the
     * template. Unknown slug renders the 404 page.
     *
     * @param array{total: int, online: int, paper: int} $signatures the live
     *   records-request count (only used by Sagamok's card; harmless to pass
     *   for every nation so the route stays simple)
     */
    public function community(string $slug, array $signatures): Response
    {
        $nation = Nations::find($slug);
        if ($nation === null) {
            return $this->renderer->html('404.html.twig', ['path' => '/communities/' . $slug], 404);
        }

        return $this->renderer->html('pages/communities/nation.html.twig', [
            'nation' => $nation,
            ...CommunityHub::context($slug, $nation, $signatures),
        ]);
    }

    /**
     * The Sagamok "Questions awaiting an answer" page. Carries the live
     * records-request signature count into the records-request item's prose,
     * the same source (PetitionRepository::signatureBreakdown()) the sign-up
     * counter and the community hub card use, so it can never go stale like
     * the hand-typed caption did in the July 2026 incident.
     *
     * @param array{total: int, online: int, paper: int} $signatures
     */
    public function sagamokAwaitingCouncil(array $signatures): Response
    {
        return $this->renderer->html('pages/communities/sagamok/awaiting-council.html.twig', [
            'signatures' => $signatures,
        ]);
    }

    /**
     * The source-backed Sagamok member accountability resolution. The public
     * payload is generated from the private campaign source and contains only
     * the resolution, public dates, and public-safe tracker steps.
     *
     * @param array<string, mixed> $data
     */
    public function sagamokAccountabilityResolution(array $data): Response
    {
        return $this->renderer->html('pages/communities/sagamok/account-or-resign.html.twig', [
            'campaign' => $data['campaign'] ?? [],
            'resolution' => $data['resolution'] ?? [],
            'members_record' => $data['members_record'] ?? [],
            'public_stages' => $data['public_stages'] ?? [],
        ]);
    }

    /**
     * A per-project Land page, rendered from the LandProjects content layer through
     * one shared template. Unknown slug renders the 404 page. Massey keeps its own
     * richer cluster at /land/massey-solar-project and is not served here.
     */
    public function landProject(string $slug): Response
    {
        $project = LandProjects::find($slug);
        if ($project === null) {
            return $this->renderer->html('404.html.twig', ['path' => '/land/' . $slug], 404);
        }

        return $this->renderer->html('pages/land/project.html.twig', ['project' => $project]);
    }

    /**
     * The /resources "Get help" directory, rendered from the Anokii graph. The
     * grouped front-door cards, sub-regions, and categories are built by
     * App\Content\ResourcesDirectory (which reads the persistent graph) and passed
     * in by the route, so the page stays in sync with the Ask box.
     *
     * @param list<array<string, mixed>> $groups
     * @param list<array{slug: string, label: string}> $regions
     * @param list<array{slug: string, label: string}> $categories
     */
    public function resourcesIndex(array $groups, array $regions, array $categories): Response
    {
        return $this->renderer->html('pages/resources/index.html.twig', [
            'groups' => $groups,
            'regions' => $regions,
            'categories' => $categories,
        ]);
    }

    /**
     * The /myth-versus-record page, rendered from the managed myth_entry content
     * type. The route resolves the entries (App\Cms\MythRepository over the
     * entities) and passes them in, in the same array shape the component used
     * when it read App\Content\MythEntries.
     *
     * @param list<array<string, mixed>> $entries
     */
    public function mythVersusRecord(array $entries): Response
    {
        return $this->renderer->html('pages/myth-versus-record.html.twig', ['myth_entries' => $entries]);
    }

    /** Permanent (301) redirect, for routes that have moved. */
    public function redirect(string $to): Response
    {
        return new RedirectResponse($to, 301);
    }

}
