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
use App\Controller\AnalyticsDashboardController;
use App\Controller\CollectController;
use App\Controller\ContactController;
use App\Controller\PageStatsController;
use App\Controller\PetitionController;
use App\Content\LandProjects;
use App\Controller\LexiconController;
use App\Controller\SiteController;
use App\Lexicon\LexiconClient;
use App\Lexicon\SqlLexiconCache;
use App\Petition\PetitionRepository;
use App\Controller\PollController;
use App\Poll\PollRepository;
use App\Rendering\SiteRenderer;
use App\Controller\SignupController;
use App\Signup\SignupRepository;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\HttpClient\StreamHttpClient;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Database\DatabaseInterface;
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

    private function petitionRepository(): PetitionRepository
    {
        return $this->petitionRepository ??= new PetitionRepository(
            $this->persistentDatabase(),
            \App\Support\HashSecret::fromEnvironment('WAASEYAA_PETITION_SECRET'),
        );
    }

    /**
     * Live total/online/paper breakdown for the records-request campaign.
     * The single place every page-render context gets this figure from, so a
     * hardcoded number can never reappear in a template.
     *
     * @return array{total: int, online: int, paper: int}
     */
    private function recordsRequestSignatures(): array
    {
        $campaign = $this->petitionRepository()->findActiveCampaign('records-request-support');

        return $campaign !== null
            ? $this->petitionRepository()->signatureBreakdown($campaign)
            : ['total' => 0, 'online' => 0, 'paper' => 0];
    }

    private ?PollRepository $pollRepository = null;

    private function pollRepository(): PollRepository
    {
        return $this->pollRepository ??= new PollRepository(
            $this->persistentDatabase(),
            \App\Support\HashSecret::fromEnvironment('WAASEYAA_POLL_SECRET'),
        );
    }

    private ?\App\Contact\ContactRepository $contactRepository = null;

    private function contactRepository(): \App\Contact\ContactRepository
    {
        return $this->contactRepository ??= new \App\Contact\ContactRepository(
            $this->persistentDatabase(),
            \App\Support\HashSecret::fromEnvironment('WAASEYAA_CONTACT_SECRET'),
        );
    }

    private ?SignupRepository $signupRepository = null;

    private function signupRepository(): SignupRepository
    {
        return $this->signupRepository ??= new SignupRepository(
            $this->persistentDatabase(),
            \App\Support\HashSecret::fromEnvironment('WAASEYAA_SIGNUP_SECRET'),
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
        $database = $this->resolve(DatabaseInterface::class);
        if (!$database instanceof DatabaseInterface) {
            throw new \LogicException('The application requires the kernel database service.');
        }

        return $this->persistentDatabase ??= $database;
    }

    public function routes(WaaseyaaRouter $router, ?\Waaseyaa\Entity\EntityTypeManager $entityTypeManager = null): void
    {
        $renderer = $this->resolve(SiteRenderer::class);
        $articleRepository = null;
        if ($entityTypeManager !== null) {
            $definitions = $this->resolveOptional(\Waaseyaa\Listing\ListingDefinitionRegistry::class);
            $listingResolver = $this->resolveOptional(\Waaseyaa\Listing\ListingResolver::class);
            if (
                $definitions instanceof \Waaseyaa\Listing\ListingDefinitionRegistry
                && $listingResolver instanceof \Waaseyaa\Listing\ListingResolver
            ) {
                $articleRepository = new \App\Cms\ArticleRepository(
                    $entityTypeManager,
                    $definitions,
                    $listingResolver,
                );
            }
        }
        $controller = new SiteController($renderer, $articleRepository);
        // The dashboard reads exclusively through ListingResolver, so the
        // seven registered ListingDefinitions are the real data path rather
        // than decoration beside a controller that scans tables.
        $monitorListings = $this->resolve(\Waaseyaa\Listing\ListingDefinitionRegistry::class);
        $monitorResolver = $this->resolve(\Waaseyaa\Listing\ListingResolver::class);
        $monitorDashboard = new \App\Controller\MonitorDashboardController(
            $entityTypeManager,
            $renderer,
            new \App\Monitor\SagamokMonitorRepository(
                $entityTypeManager,
                $monitorListings instanceof \Waaseyaa\Listing\ListingDefinitionRegistry ? $monitorListings : null,
                $monitorResolver instanceof \Waaseyaa\Listing\ListingResolver ? $monitorResolver : null,
            ),
        );
        $petition = new PetitionController($this->petitionRepository(), $renderer);
        $contact = new ContactController($this->contactRepository(), $renderer);
        $poll = new PollController($this->pollRepository(), $renderer);
        $signup = new SignupController($this->signupRepository(), $renderer);
        // Machine-readable Markdown layer (advertised in /llms.txt): pages honor
        // ?format=md / Accept: text/markdown, and the graph entities are fetchable
        // as Markdown, using the same kernel-owned database as HTTP content.
        $md = new \App\Support\MarkdownExporter($this->persistentDatabase());
        // The Get-help directory renders from the graph (front-door services).
        $directory = new \App\Content\ResourcesDirectory($this->persistentDatabase());

        $pages = \App\Content\EditorialPages::routes();

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

        $router->addRoute(
            'home',
            RouteBuilder::create('/')
                ->controller(fn (Request $request) => $md->wantsMarkdown($request)
                    ? $md->pageResponse('/')
                    : $controller->home())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'news',
            RouteBuilder::create('/news')
                ->controller(fn (Request $request) => $md->wantsMarkdown($request)
                    ? $md->pageResponse('/news')
                    : $controller->newsIndex($request->query->all()))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'news-article',
            RouteBuilder::create('/news/{slug}')
                ->controller(fn (Request $request, string $slug) => $controller->article($slug))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // Unlisted editorial review copy. Deliberately excluded from navigation,
        // sitemap.xml and the machine-readable Markdown index.
        $router->addRoute(
            'review-gr-truss-investigation-2026-07-25',
            RouteBuilder::create('/review/gr-truss-investigation-2026-07-25')
                ->controller(fn () => $controller->reviewPage('pages/review/gr-truss-investigation-2026-07-25.html.twig'))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'review-aging-well-starts-at-home-2026-07-26',
            RouteBuilder::create('/review/aging-well-starts-at-home-2026-07-26')
                ->controller(fn () => $controller->redirect('/news/aging-well-starts-before-long-term-care'))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

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
        $lexicon = new LexiconController($this->lexiconClient(), $renderer);
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
                    : $controller->community($slug, $this->recordsRequestSignatures()))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // The public-website monitor (docs/specs/sagamok-monitoring-dashboard.md).
        // Both routes are ->allowAll() at the HTTP layer and read exclusively
        // through SagamokMonitorRepository::view(), the closed projection. The
        // monitor ENTITIES stay closed: MonitorDashboardAccessPolicy grants only
        // `monitor.dashboard_read` and never `view`, so MCP, GraphQL, JSON:API,
        // Discovery and the SSR entity catch-all remain denied. Both paths sit
        // under the /communities prefix already in session.stateless_paths, so
        // anonymous readers get no cookie.
        $router->addRoute(
            'sagamok-monitor',
            RouteBuilder::create('/communities/sagamok/monitor')
                // The dashboard resolves SEVEN listings, and ListingResolver
                // reads one global `?page=` per request — so a page parameter
                // here would page every section at once and empty the small
                // ones. The dashboard is therefore the canonical un-paged view;
                // `?page=` redirects to it, and the two listings that grow have
                // their own routes where each is the only listing resolved.
                ->controller(fn (Request $request) => $request->query->has('page')
                    ? new \Symfony\Component\HttpFoundation\RedirectResponse('/communities/sagamok/monitor', 302)
                    : $monitorDashboard->dashboard(time()))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'sagamok-monitor-changes',
            RouteBuilder::create('/communities/sagamok/monitor/changes')
                ->controller(fn (Request $request) => $monitorDashboard->changes(time()))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'sagamok-monitor-pages',
            RouteBuilder::create('/communities/sagamok/monitor/pages')
                ->controller(fn (Request $request) => $monitorDashboard->pages(time()))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'sagamok-monitor-issue',
            RouteBuilder::create('/communities/sagamok/monitor/{slug}')
                ->controller(fn (Request $request, string $slug) => $monitorDashboard->issue($slug))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'sagamok-accountability',
            RouteBuilder::create('/communities/sagamok/accountability')
                ->controller(fn (Request $request) => $md->wantsMarkdown($request)
                    ? $md->pageResponse('/communities/sagamok/accountability')
                    : $controller->sagamokAccountability($this->recordsRequestSignatures()))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // The awaiting-council page states the records-request signature count
        // in prose ("Carried by N signatures..."). Registered as its own route
        // (not through the generic $pages loop above) so it can carry that live
        // figure in, the same publicCount()/paper_count source the sign-up
        // counter uses. Never hand-type this number in the template again, see
        // the July 2026 incident where a hardcoded caption drifted from the DB.
        $router->addRoute(
            'sagamok-awaiting-council',
            RouteBuilder::create('/communities/sagamok/awaiting-council')
                ->controller(fn (Request $request) => $md->wantsMarkdown($request)
                    ? $md->pageResponse('/communities/sagamok/awaiting-council')
                    : $controller->sagamokAwaitingCouncil($this->recordsRequestSignatures()))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'sagamok-member-accountability-resolution',
            RouteBuilder::create('/communities/sagamok/member-accountability-resolution')
                ->controller(fn (Request $request) => $md->wantsMarkdown($request)
                    ? $md->pageResponse('/communities/sagamok/member-accountability-resolution')
                    : $controller->sagamokAccountabilityResolution($this->sagamokAccountabilityResolutionData()))
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
            'redir-sagamok-account-or-resign' => ['/communities/sagamok/account-or-resign', '/communities/sagamok/member-accountability-resolution'],
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

        // Anonymous member polls: a page per poll (the page closure carries
        // its slug and template, same shape as the static $pages loop above)
        // plus one shared JSON vote endpoint (CSRF-exempt like the petition,
        // for the same reason: JSON body, no session to protect).
        $router->addRoute(
            'sagamok-what-matters',
            RouteBuilder::create('/communities/sagamok/what-matters')
                ->controller(fn (Request $request) => $poll->page(
                    $request,
                    'sagamok-what-matters',
                    'pages/communities/sagamok/what-matters.html.twig',
                ))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'sagamok-poll',
            RouteBuilder::create('/communities/sagamok/poll')
                ->controller(fn (Request $request) => $poll->pageMulti(
                    $request,
                    ['sagamok-poll-meetings-posted', 'sagamok-poll-evening-meetings'],
                    'pages/communities/sagamok/poll.html.twig',
                ))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'poll.vote',
            RouteBuilder::create('/api/poll/vote')
                ->controller(fn (Request $request) => $poll->vote($request))
                ->allowAll()
                ->methods('POST')
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

        // Member-owned email list (collect-only for now, see
        // working/cc-prompt-rhtcircle-list.md): the page, the JSON submit
        // endpoint (CSRF-exempt like contact/petition/analytics), and the
        // one-click remove link (GET, no login, honored immediately).
        $router->addRoute(
            'signup',
            RouteBuilder::create('/updates')
                ->controller(fn () => $signup->page())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'signup.submit',
            RouteBuilder::create('/api/signup')
                ->controller(fn (Request $request) => $signup->submit($request))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );
        $router->addRoute(
            'signup.remove',
            RouteBuilder::create('/updates/remove')
                ->controller(fn (Request $request) => $signup->remove($request))
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
        $secret = \App\Support\HashSecret::fromEnvironment('WAASEYAA_ANALYTICS_SECRET');
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
        // Audited authority for User internals (framework >= alpha.269 seals
        // roles/permissions/credentials; the sealed entity can no longer answer
        // hasPermission()/checkPassword() itself). Bound by the framework's
        // audit package; the gate, login, and create-admin all require it.
        $internalFieldReader = $this->resolve(\Waaseyaa\Access\User\UserInternalFieldReaderInterface::class);
        \assert($internalFieldReader instanceof \Waaseyaa\Access\User\UserInternalFieldReaderInterface);

        $admin = new AdminController($entityTypeManager, $database, $report, $renderer, $internalFieldReader);

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
            $internalFieldReader,
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
     * Load the generated public subset of the Sagamok member-resolution
     * campaign source. Generation performs the source-identity leak checks;
     * this loader fails closed if the committed payload is missing or invalid.
     *
     * @return array<string, mixed>
     */
    private function sagamokAccountabilityResolutionData(): array
    {
        $path = dirname(__DIR__, 2) . '/resources/content/sagamok-accountability-resolution.generated.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || !isset($data['campaign'], $data['resolution'], $data['members_record'], $data['public_stages'])) {
            throw new \RuntimeException('Generated Sagamok accountability resolution data is missing or invalid.');
        }

        return $data;
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
            name: 'app:initialize',
            description: 'Initialize app-owned schemas explicitly; does not seed or modify campaign consent records.',
            handler: function (SymfonyCommandIO $io): int {
                new \App\Content\SiteSchemaInitializer($this->persistentDatabase())->initialize();
                $io->writeln('Application schemas ready.');

                return 0;
            },
        );
        yield new HandlerCommand(
            name: 'app:seed-member-tools',
            description: 'Seed the legacy Sagamok polls and campaign definitions, including their historical aggregate counts. Explicit operator action only.',
            handler: function (SymfonyCommandIO $io): int {
                new \App\Content\MemberToolsSeeder($this->petitionRepository(), $this->pollRepository())->seed();
                $io->writeln('Legacy member tools seeded.');

                return 0;
            },
        );

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

                $reader = $this->resolve(\Waaseyaa\Access\User\UserInternalFieldReaderInterface::class);
                \assert($reader instanceof \Waaseyaa\Access\User\UserInternalFieldReaderInterface);

                return new CreateAdminHandler($etm, new AdminRoles(), 'RHTCIRCLE_ADMIN_PASSWORD', AdminRoles::ROLE_ADMIN, '/admin/login', $reader)->run($io);
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
