<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Entity\MonitorSource;
use App\Monitor\CollectorState;
use App\Monitor\FetchResult;
use App\Monitor\FixturePageFetcher;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\PublicSiteCollector;
use App\Monitor\SagamokMonitorRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Final effective-URL rejection, proven by sentinel.
 *
 * The collector is handed a response whose **effective URL is protected** and
 * whose body carries a value that cannot occur by chance. Every surface that
 * could retain or publish content is then searched for that value.
 *
 * A sentinel is the right instrument here because the failure is not "the
 * wrong field was emitted" but "a fragment of members-only content survived
 * somewhere". Asserting on field names would miss a title, a hash input, a
 * snapshot blob or an evidence note; asserting on the value catches all of
 * them at once, wherever it landed.
 *
 * The scenario is the one the body heuristics cannot see: 200 OK, no password
 * input, comfortably over the login-shell size ceiling. Only the effective URL
 * says this is members-only.
 */
final class ProtectedEffectiveUrlSentinelTest extends TestCase
{
    private const SOURCE_KEY = 'sagamok_public_site';
    private const ORIGIN = 'https://www.sagamokanishnawbek.test/';
    private const REQUESTED = self::ORIGIN . 'news/bulletin';
    private const EFFECTIVE_PROTECTED = self::ORIGIN . 'members/bulletin';

    /** Cannot occur by coincidence in markup, a hash, or a template. */
    private const SENTINEL = 'ZZSENTINELMEMBERSONLYPAYLOAD77';
    private const SENTINEL_TITLE = 'ZZSENTINELTITLE88';

    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?HttpKernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-sentinel-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=sentinel-test-secret');
        putenv('WAASEYAA_DEV_FALLBACK_ACCOUNT=false');

        $this->runCli('db:init');
    }

    protected function tearDown(): void
    {
        $this->kernel = null;
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    // ------------------------------------------------------------------

    #[Test]
    public function noSentinelFromAProtectedEffectiveUrlReachesAnySurface(): void
    {
        $this->collectProtectedResponse();

        // 1. Titles.
        foreach ($this->rowsOf('monitor_item') as $row) {
            self::assertStringNotContainsString(self::SENTINEL_TITLE, (string) ($row['title'] ?? ''), 'title');
            self::assertStringNotContainsString(self::SENTINEL, (string) ($row['title'] ?? ''), 'title');
        }

        // 2. Hashes. The stored hash must not be derived from the protected
        //    body at all — an empty hash, not a hash of members-only content.
        foreach ($this->rowsOf(CollectorState::TABLE) as $row) {
            self::assertSame('', (string) ($row['content_hash'] ?? ''), 'no hash of protected content may persist');
            self::assertSame(0, (int) ($row['normalized_bytes'] ?? 0));
        }

        // 3. Snapshots.
        self::assertSame([], $this->rowsOf(CollectorState::SNAPSHOT_TABLE), 'no snapshot may be retained');

        // 4. Events, including evidence locators and notes.
        foreach ($this->rowsOf('monitor_event') as $row) {
            $blob = implode('|', array_map(static fn (mixed $v): string => (string) $v, $row));
            self::assertStringNotContainsString(self::SENTINEL, $blob, 'event row');
            self::assertStringNotContainsString(self::SENTINEL_TITLE, $blob, 'event row');
            self::assertStringNotContainsString('/members', (string) ($row['evidence_url'] ?? ''), 'no portal locator');
        }

        // 5. Public projections — what a reader is actually handed.
        $repository = new SagamokMonitorRepository($this->manager());
        foreach ([MonitorEntityTypes::ITEM, MonitorEntityTypes::EVENT] as $typeId) {
            foreach ($this->manager()->getRepository($typeId)->findBy([]) as $entity) {
                $projected = implode('|', array_map(
                    static fn (mixed $v): string => (string) $v,
                    $repository->view($entity, 1_000_000),
                ));
                self::assertStringNotContainsString(self::SENTINEL, $projected, 'projection');
                self::assertStringNotContainsString(self::SENTINEL_TITLE, $projected, 'projection');
            }
        }

        // 6. Rendered HTML.
        $html = (string) $this->request('/communities/sagamok/monitor')->getContent();
        self::assertStringNotContainsString(self::SENTINEL, $html, 'rendered dashboard');
        self::assertStringNotContainsString(self::SENTINEL_TITLE, $html, 'rendered dashboard');
        self::assertStringNotContainsString('/members/bulletin', $html, 'no portal locator on the page');
    }

    #[Test]
    public function theSentinelIsObservableWhenTheSamePageIsPublic(): void
    {
        // MUTATION CONTROL. Identical body and title, but a PUBLIC effective
        // URL. The sentinel must now appear — otherwise the assertions above
        // would pass against a collector that stores nothing at all, or a
        // fixture whose body never reaches the pipeline.
        $this->collect(self::REQUESTED, self::REQUESTED);

        $titles = array_map(
            static fn (array $r): string => (string) ($r['title'] ?? ''),
            $this->rowsOf('monitor_item'),
        );
        self::assertNotEmpty($titles, 'a public page must produce an item');
        self::assertStringContainsString(
            self::SENTINEL_TITLE,
            implode('|', $titles),
            'the title IS derived from the body when the page is public — so its absence above is meaningful',
        );

        $hashes = array_map(
            static fn (array $r): string => (string) ($r['content_hash'] ?? ''),
            $this->rowsOf(CollectorState::TABLE),
        );
        self::assertNotSame('', implode('', $hashes), 'a public page IS hashed');
        self::assertNotSame([], $this->rowsOf(CollectorState::SNAPSHOT_TABLE), 'a public page IS snapshotted');
    }

    // ------------------------------------------------------------------

    private function collectProtectedResponse(): void
    {
        $this->collect(self::REQUESTED, self::EFFECTIVE_PROTECTED);
    }

    private function collect(string $requested, string $effective): void
    {
        // 200, no password input, well over the login-shell ceiling: every body
        // heuristic declines. Only the effective URL identifies this as
        // members-only.
        $body = '<html><head><title>' . self::SENTINEL_TITLE . '</title></head><body>'
            . str_repeat('<p>' . self::SENTINEL . ' council correspondence.</p>', 2_000)
            . '</body></html>';

        self::assertGreaterThan(60_000, strlen($body), 'must exceed the login-shell size ceiling');
        self::assertStringNotContainsString('password', $body, 'must carry no login marker');

        $fetcher = FixturePageFetcher::withPages([$requested => '<html><body>placeholder</body></html>']);
        $fetcher->set($requested, FetchResult::success(200, $effective, $body));

        $manager = $this->manager();
        new PublicSiteCollector(
            $manager->getRepository(MonitorEntityTypes::SOURCE),
            $manager->getRepository(MonitorEntityTypes::ITEM),
            $manager->getRepository(MonitorEntityTypes::EVENT),
            new CollectorState($this->database()),
            $fetcher,
        )->run($this->source(), [$requested], 1_000);
    }

    /** @return list<array<string, mixed>> */
    private function rowsOf(string $table): array
    {
        $pdo = new \PDO('sqlite:' . $this->databasePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $table . "'")
            ->fetchAll(\PDO::FETCH_ASSOC);
        if ($exists === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $pdo->query('SELECT * FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
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
            'QUERY_STRING' => '',
            'HTTP_HOST' => 'rhtcircle.ca',
            'SERVER_NAME' => 'rhtcircle.ca',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $this->projectRoot . '/public/index.php',
        ];

        return new HttpKernel($this->projectRoot)->handle();
    }

    private function source(): MonitorSource
    {
        $repository = $this->manager()->getRepository(MonitorEntityTypes::SOURCE);
        foreach ($repository->findBy(['key' => self::SOURCE_KEY]) as $existing) {
            if ($existing instanceof MonitorSource) {
                return $existing;
            }
        }

        $source = $repository->create([
            'key' => self::SOURCE_KEY,
            'label' => 'Sagamok public website',
            'origin_url' => self::ORIGIN,
            'enabled' => true,
            'health' => 'ok',
        ]);
        $repository->save($source, validate: false);

        return $source;
    }

    private function manager(): EntityTypeManager
    {
        return $this->kernel()->getEntityTypeManager();
    }

    private function database(): DatabaseInterface
    {
        return $this->kernel()->getDatabase();
    }

    private function kernel(): HttpKernel
    {
        if ($this->kernel === null) {
            $this->kernel = new HttpKernel($this->projectRoot);
            $this->kernel->bootForCli();
        }

        return $this->kernel;
    }

    private function runCli(string $command): void
    {
        $process = proc_open(
            [PHP_BINARY, $this->projectRoot . '/vendor/bin/waaseyaa', $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectRoot,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), trim($stdout . "\n" . $stderr));
    }
}
