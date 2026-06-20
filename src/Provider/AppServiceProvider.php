<?php

declare(strict_types=1);

namespace App\Provider;

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

            'treaty-wide' => ['/treaty-wide', 'pages/treaty-wide.html.twig'],
            'treaty-the-treaty' => ['/treaty-wide/the-treaty', 'pages/treaty-wide/the-treaty.html.twig'],
            'treaty-distribution-models' => ['/treaty-wide/distribution-models', 'pages/treaty-wide/distribution-models.html.twig'],

            'standard' => ['/standard', 'pages/standard.html.twig'],
            'records-request' => ['/standard/records-request', 'pages/standard/records-request.html.twig'],

            'about' => ['/about', 'pages/about.html.twig'],
            'get-involved' => ['/get-involved', 'pages/get-involved.html.twig'],

            'communities' => ['/communities', 'pages/communities/index.html.twig'],
            'community-sagamok' => ['/communities/sagamok', 'pages/communities/sagamok.html.twig'],
            'sagamok-how-organized' => ['/communities/sagamok/how-its-organized', 'pages/communities/sagamok/how-its-organized.html.twig'],
            'sagamok-massey' => ['/communities/sagamok/massey', 'pages/communities/sagamok/massey.html.twig'],
            'sagamok-massey-what-youve-heard' => ['/communities/sagamok/massey-what-youve-heard', 'pages/communities/sagamok/massey-what-youve-heard.html.twig'],
            'sagamok-massey-voices' => ['/communities/sagamok/massey-voices', 'pages/communities/sagamok/massey-voices.html.twig'],
            'sagamok-massey-climate' => ['/communities/sagamok/massey-climate', 'pages/communities/sagamok/massey-climate.html.twig'],
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
    }
}
