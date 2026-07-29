<?php

declare(strict_types=1);

namespace App\Provider;

use App\Cms\ArticleRepository;
use App\Publishing\ArticleContentType;
use App\Publishing\ArticlePreviewController;
use App\Publishing\ArticlePublisherAccount;
use App\Publishing\SitemapController;
use App\Rendering\SiteRenderer;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Tools\Content\ContentToolSet;
use Waaseyaa\AI\Tools\Content\MediaAssetStore;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\WriteTierAuthInterface;
use Waaseyaa\Publishing\ContentPublisher;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;
use Waaseyaa\Publishing\Preview\PreviewLinkService;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * The MCP article-publishing wiring: thin app glue over the framework's
 * publishing surface.
 *
 * - `register()` binds the write-tier bearer auth: the token comes from the
 *   RHTCIRCLE_MCP_PUBLISHER_TOKEN env var (dotenv, never Git); no token →
 *   no binding → the framework's fail-closed default 401s `/mcp/write`.
 * - `boot()` registers the `article.*` / `asset.*` MCP tool set (framework
 *   ContentToolSet over the app's ArticleContentType descriptor).
 * - `routes()` wires the signed draft-preview route (real article layout,
 *   noindex) and the dynamic `/sitemap.xml`.
 *
 * NO publishing logic lives here or anywhere else in the app: every mutation
 * goes through the framework ContentPublisher (authorization, validation,
 * sanitization, revisions, idempotency, audit).
 */
final class PublishingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $token = (string) (getenv('RHTCIRCLE_MCP_PUBLISHER_TOKEN') ?: '');
        if ($token !== '') {
            $this->singleton(
                WriteTierAuthInterface::class,
                static fn(): WriteTierAuthInterface => new BearerTokenAuth([$token => new ArticlePublisherAccount()]),
            );
        }
    }

    public function boot(): void
    {
        try {
            $registry = $this->resolve(ToolRegistryInterface::class);
            $publisher = $this->buildPublisher();
            $etm = $this->entityTypeManager();
            \assert($registry instanceof ToolRegistryInterface);

            $assets = new MediaAssetStore(
                $etm->getRepository('media'),
                $this->uploadsDir(),
                '/media/uploads',
            );

            new ContentToolSet(
                $publisher,
                ArticleContentType::descriptor(),
                $this->previewLinks(),
                static fn(string $id, int $exp, string $sig): string => sprintf('/news/preview/%s?exp=%d&sig=%s', $id, $exp, $sig),
                $assets,
            )->register($registry, 'article');
        } catch (\Throwable $e) {
            // The public site must render even if the publishing surface
            // cannot wire (e.g. minimal boots without the tool registry).
            // The acceptance flow proves the wired path; this is not a
            // publishing fallback — there is none.
            error_log('[rhtcircle] article publishing tools unavailable: ' . $e->getMessage());
        }
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        if ($entityTypeManager === null) {
            return;
        }

        $router->addRoute('sitemap', RouteBuilder::create('/sitemap.xml')
            ->controller(fn(): \Symfony\Component\HttpFoundation\Response => new SitemapController($this->articleRepository($entityTypeManager))->sitemap())
            ->allowAll()
            ->methods('GET')
            ->priority(50)
            ->build());

        $router->addRoute('media-uploads', RouteBuilder::create('/media/uploads/{name}')
            ->controller(function (string $name): \Symfony\Component\HttpFoundation\Response {
                // Content-addressed names only — the pattern IS the authorization
                // (published asset URLs are public); no traversal is expressible.
                if (preg_match('/^[a-f0-9]{64}\\.(png|jpg|webp)$/', $name) !== 1) {
                    return new \Symfony\Component\HttpFoundation\Response('Not found', 404);
                }
                $file = $this->uploadsDir() . '/' . $name;
                if (!is_file($file)) {
                    return new \Symfony\Component\HttpFoundation\Response('Not found', 404);
                }
                $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][pathinfo($name, PATHINFO_EXTENSION)];

                return new \Symfony\Component\HttpFoundation\BinaryFileResponse($file, 200, [
                    'Content-Type' => $mime,
                    'Cache-Control' => 'public, max-age=31536000, immutable',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            })
            ->allowAll()
            ->methods('GET')
            ->priority(60)
            ->build());

        $router->addRoute('news-article-preview', RouteBuilder::create('/news/preview/{nid}')
            ->controller(function (Request $request, string $nid) use ($entityTypeManager): \Symfony\Component\HttpFoundation\Response {
                $renderer = $this->resolve(SiteRenderer::class);
                \assert($renderer instanceof SiteRenderer);

                return new ArticlePreviewController(
                    $entityTypeManager,
                    $this->articleRepository($entityTypeManager),
                    $renderer,
                    $this->previewLinks(),
                )->preview($request, $nid);
            })
            ->allowAll()
            ->methods('GET')
            ->priority(60)
            ->build());
    }

    private function buildPublisher(): ContentPublisher
    {
        $repository = $this->entityTypeManager()->getRepository('node');
        \assert($repository instanceof EntityRepository);

        $database = $this->resolve(DatabaseInterface::class);
        \assert($database instanceof DatabaseInterface);

        $audit = $this->resolveOptional(AuditWriterInterface::class);
        $accessHandler = $this->resolveOptional(EntityAccessHandler::class);

        return new ContentPublisher(
            ArticleContentType::descriptor(),
            $repository,
            new IdempotencyStore($database),
            $audit instanceof AuditWriterInterface ? $audit : null,
            $accessHandler instanceof EntityAccessHandler ? $accessHandler : null,
        );
    }

    private function previewLinks(): PreviewLinkService
    {
        $secret = $this->resolve(ApplicationSecret::class);
        \assert($secret instanceof ApplicationSecret);

        return new PreviewLinkService($secret->derive('waaseyaa.publishing.preview.v1'));
    }

    private function articleRepository(EntityTypeManager $etm): ArticleRepository
    {
        $definitions = $this->resolve(ListingDefinitionRegistry::class);
        $resolver = $this->resolve(ListingResolver::class);
        \assert($definitions instanceof ListingDefinitionRegistry && $resolver instanceof ListingResolver);

        return new ArticleRepository($etm, $definitions, $resolver);
    }

    private function entityTypeManager(): EntityTypeManager
    {
        $etm = $this->resolve(EntityTypeManager::class);
        \assert($etm instanceof EntityTypeManager);

        return $etm;
    }

    /**
     * Persistent uploads directory: lives on the storage/ volume (the same
     * persisted mount as the SQLite database), NEVER inside the rebuildable
     * application tree. Explicitly created on wiring; override with
     * WAASEYAA_MEDIA_UPLOADS_DIR for bespoke mounts.
     */
    private function uploadsDir(): string
    {
        $dir = (string) (getenv('WAASEYAA_MEDIA_UPLOADS_DIR') ?: \dirname(__DIR__, 2) . '/storage/media-uploads');
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Media uploads directory could not be created: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('Media uploads directory is not writable: ' . $dir);
        }

        return $dir;
    }
}
