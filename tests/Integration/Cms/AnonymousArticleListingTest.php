<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\ArticleRepository;
use App\Provider\AppServiceProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ListingResolver;

final class AnonymousArticleListingTest extends TestCase
{
    private string $projectRoot;
    private string $databasePath;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-cms-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB'] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);

        $this->runCli('db:init');
        $this->runCli('app:cms-migrate-articles');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }

        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    public function testAnonymousNewsAndSagamokListingsContainMigratedArticles(): void
    {
        $kernel = new HttpKernel($this->projectRoot);
        $kernel->bootForCli();

        $provider = null;
        foreach ($kernel->getProviders() as $candidate) {
            if ($candidate instanceof AppServiceProvider) {
                $provider = $candidate;
                break;
            }
        }

        self::assertInstanceOf(AppServiceProvider::class, $provider);
        $definitions = $provider->resolve(ListingDefinitionRegistry::class);
        $resolver = $provider->resolve(ListingResolver::class);
        self::assertInstanceOf(ListingDefinitionRegistry::class, $definitions);
        self::assertInstanceOf(ListingResolver::class, $resolver);

        $articles = new ArticleRepository($kernel->getEntityTypeManager(), $definitions, $resolver);
        self::assertCount(6, $articles->published());
        self::assertCount(5, $articles->forSagamok());

        $published = $resolver->resolve($definitions->get(ArticleRepository::LISTING_ALL));
        self::assertCount(6, $published->rows);
        self::assertSame(6, $published->pagination->totalRows);

        $sagamok = $resolver->resolve($definitions->get(ArticleRepository::LISTING_SAGAMOK));
        self::assertCount(5, $sagamok->rows);
        self::assertSame(5, $sagamok->pagination->totalRows);

        $news = $this->request('/news');
        self::assertSame(200, $news->getStatusCode());
        self::assertStringContainsString('/news/sagamok-trespass-bylaw-session-was-backwards', (string) $news->getContent());
        self::assertStringContainsString('/news/sagamok-south-market-land-deal', (string) $news->getContent());

        $hub = $this->request('/communities/sagamok/accountability');
        self::assertSame(200, $hub->getStatusCode());
        self::assertStringContainsString('/news/sagamok-trespass-bylaw-session-was-backwards', (string) $hub->getContent());
        self::assertStringContainsString('/news/sagamok-south-market-land-deal', (string) $hub->getContent());
    }

    private function request(string $uri): \Symfony\Component\HttpFoundation\Response
    {
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $this->projectRoot . '/public/index.php',
        ];

        return new HttpKernel($this->projectRoot)->handle();
    }

    private function runCli(string $command): void
    {
        $process = proc_open(
            [PHP_BINARY, $this->projectRoot . '/vendor/bin/waaseyaa', $command],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->projectRoot,
        );

        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, trim($stdout . "\n" . $stderr));
    }
}
