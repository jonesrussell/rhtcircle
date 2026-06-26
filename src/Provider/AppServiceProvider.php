<?php

declare(strict_types=1);

namespace App\Provider;

use Anokii\Access\AdminRoles;
use Anokii\Admin\CreateAdminHandler;
use Anokii\Dashboard\AdminLoginController;
use Anokii\Dashboard\LoginBrand;
use App\Admin\AdminController;
use App\Analytics\AnalyticsRecorder;
use App\Analytics\AnalyticsReport;
use App\Analytics\AnalyticsSchema;
use App\Controller\AnalyticsDashboardController;
use App\Controller\CollectController;
use App\Controller\ContactController;
use App\Controller\PageStatsController;
use App\Controller\PetitionController;
use App\Content\LandProjects;
use App\Controller\LexiconController;
use App\Controller\SiteController;
use App\Lexicon\LexiconCacheSchema;
use App\Lexicon\LexiconClient;
use App\Lexicon\SqlLexiconCache;
use App\Petition\PetitionRepository;
use App\Petition\PetitionSchema;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\HttpClient\StreamHttpClient;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class AppServiceProvider extends ServiceProvider implements ProvidesRolesInterface, ProvidesConsoleCommandsInterface
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
            // Public contact form: ensure its table on the same persistent file.
            new \App\Contact\ContactSchema($this->persistentDatabase())->ensure();
            // Anishinaabemowin lookup cache (Minoo language API). Ensured here on
            // the persistent file for the same reason as the petition below.
            new LexiconCacheSchema($this->persistentDatabase())->ensure();
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
                39,
                'Paper signatures handed to the Sagamok band office: 16 on June 15, 2026, 10 on June 22, 2026, and 3 on June 25, 2026. Plus 10 members who signed on paper and asked to be counted only, not named, accounted on June 25, 2026.',
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

    private ?\App\Contact\ContactRepository $contactRepository = null;

    private function contactRepository(): \App\Contact\ContactRepository
    {
        return $this->contactRepository ??= new \App\Contact\ContactRepository(
            $this->persistentDatabase(),
            getenv('WAASEYAA_CONTACT_SECRET') ?: (getenv('WAASEYAA_JWT_SECRET') ?: 'rhtcircle-contact'),
        );
    }

    private ?LexiconClient $lexiconClient = null;

    /**
     * The Anishinaabemowin lookup client (Minoo language API), server-to-server.
     * A short HTTP timeout keeps a slow Minoo from stalling the page, and the
     * cache is pinned to the persistent SQLite file (route-build resolve() can be
     * ephemeral, same rationale as the petition/analytics wiring). Base URL from
     * MINOO_LANG_API_URL, else the client's default (https://minoo.live/api/lang).
     */
    private function lexiconClient(): LexiconClient
    {
        return $this->lexiconClient ??= new LexiconClient(
            new StreamHttpClient(2.5),
            new SqlLexiconCache($this->persistentDatabase()),
            getenv('MINOO_LANG_API_URL') ?: null,
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
        $contact = new ContactController($this->contactRepository());
        // Machine-readable Markdown layer (advertised in /llms.txt): pages honor
        // ?format=md / Accept: text/markdown, and the graph entities are fetchable
        // as Markdown. Reads the persistent file (route-build resolve() can be
        // ephemeral), same rationale as the petition/analytics wiring below.
        $md = new \App\Support\MarkdownExporter($this->persistentDatabase());
        // The Get-help directory renders from the graph (front-door services).
        $directory = new \App\Content\ResourcesDirectory($this->persistentDatabase());

        $pages = [
            'home' => ['/', 'pages/home.html.twig'],

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

            'sagamok-how-organized' => ['/communities/sagamok/how-its-organized', 'pages/communities/sagamok/how-its-organized.html.twig'],
            'sagamok-members-website-issue' => ['/communities/sagamok/members-website-issue', 'pages/communities/sagamok/members-website-issue.html.twig'],
        ];

        foreach ($pages as $name => [$path, $template]) {
            $router->addRoute(
                $name,
                RouteBuilder::create($path)
                    ->controller(fn (Request $request) => $md->wantsMarkdown($request)
                        ? $md->pageResponse($path)
                        : $controller->page($template))
                    ->allowAll()
                    ->methods('GET')
                    ->build(),
            );
        }

        // The Land: each new project profile is data-driven from App\Content\LandProjects
        // through one shared template. Registered as explicit paths (not a /land/{slug}
        // param route) so they never shadow the Massey cluster registered above.
        foreach (LandProjects::all() as $project) {
            $slug = (string) $project['slug'];
            $router->addRoute(
                'land-project-' . $slug,
                RouteBuilder::create('/land/' . $slug)
                    ->controller(fn (Request $request) => $md->wantsMarkdown($request)
                        ? $md->pageResponse('/land/' . $slug)
                        : $controller->landProject($slug))
                    ->allowAll()
                    ->methods('GET')
                    ->build(),
            );
        }

        // Resources "Get help": graph-driven directory (front-door services
        // grouped by category, with sub-region + coordinates). Honors ?format=md
        // like the other content pages.
        $router->addRoute(
            'resources',
            RouteBuilder::create('/resources')
                ->controller(fn (Request $request) => $md->wantsMarkdown($request)
                    ? $md->pageResponse('/resources')
                    : $controller->resourcesIndex($directory->groups(), $directory->regions(), $directory->categories()))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // The Treaty: our language. Registered explicitly (not in the static
        // $pages table) because it carries a server-side Anishinaabemowin lookup:
        // the controller reads ?q= and calls Minoo's language API server-to-server
        // (Minoo has no CORS), fail-soft, with attribution rendered. Still honors
        // ?format=md for the base page, like the other content pages.
        $lexicon = new LexiconController($this->lexiconClient());
        $router->addRoute(
            'treaty-language',
            RouteBuilder::create('/treaty/language')
                ->controller(fn (Request $request) => $md->wantsMarkdown($request)
                    ? $md->pageResponse('/treaty/language')
                    : $lexicon->page($request))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // Myth versus record: the first managed content type. Renders from the
        // myth_entry + source_link entities (App\Cms\MythRepository), falling back
        // to the MythEntries array only if the entity system is unavailable at
        // route-build, so the page never breaks. Honors ?format=md like the others.
        $mythEntries = static fn (): array => $entityTypeManager !== null
            ? new \App\Cms\MythRepository($entityTypeManager)->ordered()
            : \App\Content\MythEntries::ordered();
        $router->addRoute(
            'myth-versus-record',
            RouteBuilder::create('/myth-versus-record')
                ->controller(fn (Request $request) => $md->wantsMarkdown($request)
                    ? $md->pageResponse('/myth-versus-record')
                    : $controller->mythVersusRecord($mythEntries()))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // Communities: the index and the 21 per-nation pages are data-driven from
        // App\Content\Nations, so the controller passes context. The {slug} route
        // matches a single segment, so it never shadows /communities or the deeper
        // /communities/sagamok/* pages registered above.
        $router->addRoute(
            'communities',
            RouteBuilder::create('/communities')
                ->controller(fn (Request $request) => $md->wantsMarkdown($request)
                    ? $md->pageResponse('/communities')
                    : $controller->communitiesIndex())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'community-profile',
            RouteBuilder::create('/communities/{slug}')
                ->controller(fn (Request $request, string $slug) => $md->wantsMarkdown($request)
                    ? $md->pageResponse('/communities/' . $slug)
                    : $controller->community($slug))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // Machine-readable entity surface: /{type}/{slug-or-id} returns Markdown
        // for the graph entities the chat and llms.txt reference. These paths have
        // no HTML page in this app, so they serve Markdown regardless of format.
        foreach (['place', 'community', 'organization', 'service', 'project', 'topic', 'doc_chunk'] as $etype) {
            $router->addRoute(
                'md.entity.' . $etype,
                RouteBuilder::create('/' . $etype . '/{key}')
                    ->controller(fn (Request $request, string $key) => $md->entityResponse($etype, $key))
                    ->allowAll()
                    ->methods('GET')
                    ->build(),
            );
        }

        // Real /llms.txt, generated from this site's pages and primary entities.
        // priority(20) beats the framework's generic seo.llms_txt (priority 10),
        // whose placeholder topics ("place 1") linked to unrouted /{type}/{id}.
        $router->addRoute(
            'app.llms_txt',
            RouteBuilder::create('/llms.txt')
                ->controller(fn () => $md->llmsTxtResponse())
                ->allowAll()
                ->methods('GET')
                ->priority(20)
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
            // Community safety moved out of The Land into its own section.
            'redir-territory-and-safety' => ['/land/territory-and-safety', '/safety/hate-and-extremism'],
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

        // Contact form: the public page and a JSON submit endpoint (CSRF-exempt
        // like the petition/analytics beacons). Stored on the Circle's database;
        // listed in the gated admin (no mailer wired yet).
        $router->addRoute(
            'contact',
            RouteBuilder::create('/contact')
                ->controller(fn () => $contact->page())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'contact.submit',
            RouteBuilder::create('/api/contact')
                ->controller(fn (Request $request) => $contact->submit($request))
                ->allowAll()
                ->methods('POST')
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
        // Admin dashboards, gated by the framework's own auth (NOT Caddy basic
        // auth, which has been removed). The routes are allowAll() at the framework
        // layer and the AdminController enforces the session + the admin permission
        // itself (reusing the Anokii package's DashboardGate / Support\Auth /
        // AbstractWorkspaceRoles): an unauthenticated request redirects to
        // /admin/login, a non-admin account gets 403, an admin sees the dashboard.
        //
        // /admin/anokii is registered HERE now (the package's anokii-admin module is
        // disabled in config/anokii.yaml) so it goes through the same gate as
        // /admin/analytics. priority(100) beats the framework admin SPA catch-all
        // at /admin/{path} (priority 0).
        $admin = new AdminController($entityTypeManager, $database, $report);

        // Login surface from the shared package (Anokii\Dashboard\AdminLoginController),
        // branded for rhtcircle and gating on the package admin permission. Replaces
        // the per-app login flow that used to live in AdminController.
        $login = new AdminLoginController(
            $entityTypeManager,
            '/admin/login',
            '/admin/anokii',
            AdminRoles::DEFAULT_PERMISSION,
            new LoginBrand(
                title: 'Admin sign in · Robinson Huron Treaty',
                subtitle: 'Administrator access for the Robinson Huron Treaty hub.',
                accent: '#4f2fb0',
                accentDeep: '#38217f',
                link: '#c41d8f',
                backHref: '/',
                backLabel: 'Back to the public site',
            ),
            '/admin',
        );

        $adminGet = static fn (string $name, string $path, callable $c) => $router->addRoute(
            $name,
            RouteBuilder::create($path)->controller($c)->allowAll()->methods('GET')->priority(100)->build(),
        );
        $adminPost = static fn (string $name, string $path, callable $c) => $router->addRoute(
            $name,
            RouteBuilder::create($path)->controller($c)->allowAll()->methods('POST')->priority(100)->build(),
        );

        $adminGet('admin.login', '/admin/login', fn (Request $request) => $login->loginForm($request));
        $adminPost('admin.login.post', '/admin/login', fn (Request $request) => $login->loginSubmit($request));
        $adminGet('admin.logout', '/admin/logout', fn (Request $request) => $login->logout($request));
        // Anokii admin, rendered through the shared package shell. All under
        // /admin/anokii so the canonical module paths match Anokii\Admin\AdminModules.
        $adminGet('admin.anokii', '/admin/anokii', fn (Request $request) => $admin->home($request));
        $adminGet('admin.anokii.cointelligence', '/admin/anokii/cointelligence', fn (Request $request) => $admin->cointelligence($request));
        $adminGet('admin.anokii.analytics', '/admin/anokii/analytics', fn (Request $request) => $admin->analytics($request));
        $adminGet('admin.anokii.contact', '/admin/anokii/contact', fn (Request $request) => $admin->contact($request, $this->contactRepository()));
        $adminGet('admin.anokii.module', '/admin/anokii/m/{module}', fn (Request $request, string $module) => $admin->comingSoon($request, $module));
        // The analytics dashboard moved under /admin/anokii; one-hop 301 the old path.
        $adminGet('admin.analytics.redirect', '/admin/analytics', fn (Request $request) => new \Symfony\Component\HttpFoundation\RedirectResponse('/admin/anokii/analytics', 301));
    }

    /**
     * Contribute the rht admin roles to the framework RoleRepository so
     * `user:assign-role` can resolve them and stamp ACCESS_ADMIN. The single
     * operator account is given the framework `administrator` role by
     * app:create-admin; this also makes the dashboard-only operator role available.
     *
     * @return iterable<\Waaseyaa\User\Role>
     */
    public function roles(): iterable
    {
        yield from new AdminRoles()->roles();
    }

    /**
     * @return iterable<HandlerCommand>
     */
    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'app:create-admin',
            description: 'Create or update the administrator account for the gated /admin dashboards. Password from --password or RHTCIRCLE_ADMIN_PASSWORD (never hardcoded).',
            arguments: [
                new HandlerArgument(name: 'email', mode: HandlerArgumentMode::Required, description: 'Email address of the admin account.'),
            ],
            options: [
                new HandlerOption(name: 'name', mode: HandlerOptionMode::Required, description: 'Display name for the account.'),
                new HandlerOption(name: 'password', mode: HandlerOptionMode::Required, description: 'Password (else read from RHTCIRCLE_ADMIN_PASSWORD). At least 12 characters.'),
            ],
            handler: function (SymfonyCommandIO $io): int {
                $etm = $this->adminEntityTypeManager();
                if ($etm === null) {
                    $io->error('app:create-admin requires a booted kernel (EntityTypeManager).');

                    return 1;
                }

                return new CreateAdminHandler($etm, new AdminRoles(), 'RHTCIRCLE_ADMIN_PASSWORD', AdminRoles::ROLE_ADMIN, '/admin/login')->run($io);
            },
        );
    }

    private function adminEntityTypeManager(): ?EntityTypeManager
    {
        try {
            $resolved = $this->resolve(EntityTypeManager::class);

            return $resolved instanceof EntityTypeManager ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
