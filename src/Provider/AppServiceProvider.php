<?php

declare(strict_types=1);

namespace App\Provider;

use App\Analytics\AnalyticsRecorder;
use App\Analytics\AnalyticsReport;
use App\Analytics\AnalyticsSchema;
use App\Controller\AnalyticsDashboardController;
use App\Controller\CollectController;
use App\Controller\PageStatsController;
use App\Controller\PetitionController;
use App\Controller\SiteController;
use App\Petition\PetitionRepository;
use App\Petition\PetitionSchema;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class AppServiceProvider extends ServiceProvider
{
    private ?DatabaseInterface $persistentDatabase = null;
    private ?PetitionRepository $petitionRepository = null;

    public function register(): void {}

    /**
     * Ensure the petition tables and seed the records-request campaign on the
     * persistent SQLite file (the same file the controllers read and write).
     * Wrapped so a storage hiccup can never take down the static pages: the
     * sign-on is additive to a site that otherwise renders without a database.
     */
    public function boot(): void
    {
        try {
            // First-party analytics: ensure the append-only event table on the
            // persistent file (same pin-to-file rationale as the petition below).
            new AnalyticsSchema($this->persistentDatabase())->ensure();

            new PetitionSchema($this->persistentDatabase())->ensure();
            $repo = $this->petitionRepository();
            $repo->ensureCampaign(
                'records-request-support',
                'Support the member records request',
                'We, the undersigned members of Sagamok Anishnawbek, support the records request submitted to Chief and Council. We want clear answers, on the record, to one question: when the Nation invests in businesses and ventures, what are the benefits to the membership, and who is being served? We ask Council to provide the records and respond to the membership.',
                'Sagamok Chief and Council',
            );
            // online_base stays 0: the real online sign-ons were migrated from
            // oiatc as rows, so they carry the live count themselves. The paper
            // count + its dated provenance note are carried over from oiatc
            // (aggregate only, no PII). Bump the paper count as more are handed
            // in.
            $repo->setOnlineBase('records-request-support', 0);
            $repo->setPaperCount(
                'records-request-support',
                16,
                'Includes signatures collected on paper and handed to the Sagamok band office (political office) on June 15, 2026.',
            );
        } catch (\Throwable) {
            // Additive feature; never let it break page rendering.
        }
    }

    private function petitionRepository(): PetitionRepository
    {
        return $this->petitionRepository ??= new PetitionRepository(
            $this->persistentDatabase(),
            getenv('WAASEYAA_PETITION_SECRET') ?: (getenv('WAASEYAA_JWT_SECRET') ?: 'rhtcircle-petition'),
        );
    }

    /**
     * A DatabaseInterface pinned to the persistent SQLite file. resolve() at
     * boot/route-build time can hand back an ephemeral connection (controllers
     * are built once, not per request), so signature writes must share this
     * file-backed connection instead.
     */
    private function persistentDatabase(): DatabaseInterface
    {
        return $this->persistentDatabase ??= DBALDatabase::createSqlite($this->databasePath());
    }

    /** The app's SQLite path: WAASEYAA_DB if set, else storage/waaseyaa.sqlite. */
    private function databasePath(): string
    {
        $root = \dirname(__DIR__, 2);
        $configured = getenv('WAASEYAA_DB') ?: '';
        if ($configured === '') {
            return $root . '/storage/waaseyaa.sqlite';
        }
        $isAbsolute = str_starts_with($configured, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $configured) === 1;

        return $isAbsolute ? $configured : $root . '/' . ltrim($configured, './');
    }

    public function routes(WaaseyaaRouter $router, ?\Waaseyaa\Entity\EntityTypeManager $entityTypeManager = null): void
    {
        $controller = new SiteController();
        $petition = new PetitionController($this->petitionRepository());

        $pages = [
            'home' => ['/', 'pages/home.html.twig'],

            // The Treaty: orientation pillar. The four-part annuity explainer and
            // its distribution-models companion migrated here from /treaty-wide
            // (301s below); fixed-content pages, no context needed.
            'treaty' => ['/treaty', 'pages/treaty/index.html.twig'],
            'treaty-distribution-models' => ['/treaty/distribution-models', 'pages/treaty/distribution-models.html.twig'],

            // Transparency: the settlement asks and the shared standard.
            'treaty-wide' => ['/treaty-wide', 'pages/treaty-wide.html.twig'],
            'standard' => ['/standard', 'pages/standard.html.twig'],
            'records-request' => ['/standard/records-request', 'pages/standard/records-request.html.twig'],

            'land' => ['/land', 'pages/land/index.html.twig'],
            'land-massey' => ['/land/massey-solar-project', 'pages/land/massey-solar-project.html.twig'],
            'land-massey-what-youve-heard' => ['/land/massey-solar-project/what-youve-heard', 'pages/land/massey-solar-project/what-youve-heard.html.twig'],
            'land-massey-voices' => ['/land/massey-solar-project/voices', 'pages/land/massey-solar-project/voices.html.twig'],
            'land-massey-climate' => ['/land/massey-solar-project/climate', 'pages/land/massey-solar-project/climate.html.twig'],
            'land-territory-and-safety' => ['/land/territory-and-safety', 'pages/land/territory-and-safety.html.twig'],

            // The Circle: the member-led movement. About: what the hub is and is not.
            'circle' => ['/circle', 'pages/circle/index.html.twig'],
            'about' => ['/about', 'pages/about.html.twig'],
            'get-involved' => ['/get-involved', 'pages/get-involved.html.twig'],

            'sagamok-how-organized' => ['/communities/sagamok/how-its-organized', 'pages/communities/sagamok/how-its-organized.html.twig'],
            'sagamok-members-website-issue' => ['/communities/sagamok/members-website-issue', 'pages/communities/sagamok/members-website-issue.html.twig'],
        ];

        foreach ($pages as $name => [$path, $template]) {
            $router->addRoute(
                $name,
                RouteBuilder::create($path)
                    ->controller(fn () => $controller->page($template))
                    ->allowAll()
                    ->methods('GET')
                    ->build(),
            );
        }

        // Communities: the index and the 21 per-nation pages are data-driven from
        // App\Content\Nations, so the controller passes context. The {slug} route
        // matches a single segment, so it never shadows /communities or the deeper
        // /communities/sagamok/* pages registered above.
        $router->addRoute(
            'communities',
            RouteBuilder::create('/communities')
                ->controller(fn () => $controller->communitiesIndex())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'community-profile',
            RouteBuilder::create('/communities/{slug}')
                ->controller(fn (Request $request, string $slug) => $controller->community($slug))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // 301 redirects from old paths. Each lands in one hop on a live page, so
        // no chains. The Massey set catches old/external inbound links from when
        // Massey lived under the Sagamok community bucket. The treaty set catches
        // the explainer's old /treaty-wide home after it moved to /treaty; all
        // internal links were repointed in the same change.
        $redirects = [
            'redir-massey' => ['/communities/sagamok/massey', '/land/massey-solar-project'],
            'redir-massey-what-youve-heard' => ['/communities/sagamok/massey-what-youve-heard', '/land/massey-solar-project/what-youve-heard'],
            'redir-massey-voices' => ['/communities/sagamok/massey-voices', '/land/massey-solar-project/voices'],
            'redir-massey-climate' => ['/communities/sagamok/massey-climate', '/land/massey-solar-project/climate'],
            'redir-treaty-the-treaty' => ['/treaty-wide/the-treaty', '/treaty'],
            'redir-treaty-distribution-models' => ['/treaty-wide/distribution-models', '/treaty/distribution-models'],
        ];
        foreach ($redirects as $name => [$from, $to]) {
            $router->addRoute(
                $name,
                RouteBuilder::create($from)
                    ->controller(fn () => $controller->redirect($to))
                    ->allowAll()
                    ->methods('GET')
                    ->build(),
            );
        }

        // Petition: public sign-on, live count, and one-click removal. JSON
        // endpoints (CSRF-exempt, like the analytics beacon) plus a themed
        // remove-result page.
        $router->addRoute(
            'petition.sign',
            RouteBuilder::create('/api/petition/sign')
                ->controller(fn (Request $request) => $petition->sign($request))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );
        $router->addRoute(
            'petition.info',
            RouteBuilder::create('/api/petition/{slug}')
                ->controller(fn (Request $request, string $slug) => $petition->info($slug))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'petition.remove',
            RouteBuilder::create('/petition/remove/{token}')
                ->controller(fn (Request $request, string $token) => $petition->remove($token))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // First-party, self-hosted analytics. Fully first-party: a same-origin
        // JSON beacon (wired site-wide in base.html.twig) writes to our own
        // SQLite; the dashboard reads it back. No third party, no ad-tech, no
        // cookies. Pinned to the persistent file for the same reason as the
        // petition: resolve(DatabaseInterface) at route-build time can hand back
        // an ephemeral connection, so beacon writes wired to it would never reach
        // storage/waaseyaa.sqlite and the dashboard would read an empty DB.
        $database = $this->persistentDatabase();
        $secret = getenv('WAASEYAA_ANALYTICS_SECRET')
            ?: (getenv('WAASEYAA_JWT_SECRET') ?: 'rhtcircle-analytics');
        $report = new AnalyticsReport($database);
        $collect = new CollectController(new AnalyticsRecorder($database, $secret));
        $analytics = new AnalyticsDashboardController($report);
        $pageStats = new PageStatsController($report);

        $router->addRoute(
            'analytics.collect',
            RouteBuilder::create('/api/collect')
                ->controller(fn (Request $request) => $collect->collect($request))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );
        $router->addRoute(
            'analytics.page-stats',
            RouteBuilder::create('/api/page-stats')
                ->controller(fn (Request $request) => $pageStats->stats($request))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
        // Public at the app layer; gated in production by Caddy basic auth on
        // /admin/* (see waaseyaa-infra). Carries a noindex meta tag regardless.
        // priority(10) is required so this exact route wins over the framework
        // admin SPA's catch-all (/admin/{path}, priority 0), which would
        // otherwise serve its bundled Nuxt app here and shadow the dashboard.
        $router->addRoute(
            'admin.analytics',
            RouteBuilder::create('/admin/analytics')
                ->controller(fn (Request $request) => $analytics->index($request))
                ->allowAll()
                ->methods('GET')
                ->priority(10)
                ->build(),
        );
    }
}
