<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Monitor\MonitorEntityTypes;
use App\Monitor\SagamokMonitorRepository;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Framework #2165, asserted from the application side.
 *
 * Several monitor fields are declared `FieldStorage::Data` on entity types that
 * use the `sql-column` backend — `monitor_issue.summary` and `what_is_asked`,
 * `portal_access_state.statement`, and others. Under alpha.282 every one of them
 * was **silently discarded on save**: the column existed, `save()` returned
 * success, and the value was `NULL` on reload. The dashboard rendered empty
 * paragraphs where the issue summary and the portal statement should have been.
 *
 * The declarations are deliberately unchanged. Redeclaring them
 * `FieldStorage::Column` would have been an app-level workaround for a framework
 * bug and would have left the trap in place for the next application, so the fix
 * went upstream (alpha.283) and this test guards the app's side of it.
 *
 * It runs against the **real kernel** and the real repositories, because that is
 * the composition the defect lived in.
 */
final class DataFieldPersistenceTest extends TestCase
{
    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?HttpKernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-datafield-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=datafield-test-secret');
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

    /**
     * The affected fields, discovered from the declarations rather than listed
     * by hand: every `FieldStorage::Data` field on a monitor entity type. A
     * field added later is covered automatically.
     *
     * @return array<string, list<string>>
     */
    private function dataDeclaredFields(): array
    {
        $out = [];
        foreach (MonitorEntityTypes::CLASSES as $typeId => $class) {
            $names = [];
            foreach (EntityMetadataReader::resolveFields($class) as $name => $definition) {
                if ($definition->getStored() === FieldStorage::Data) {
                    $names[] = $name;
                }
            }
            if ($names !== []) {
                $out[$typeId] = $names;
            }
        }

        return $out;
    }

    public function testTheMonitorTypesStillDeclareDataStoredFields(): void
    {
        // Guards the premise. If someone "fixes" this by redeclaring the fields
        // as Column, the rest of this file would pass vacuously while the
        // framework behaviour it exists to prove went untested.
        $declared = $this->dataDeclaredFields();

        self::assertNotEmpty($declared, 'the monitor still declares FieldStorage::Data fields');
        self::assertContains('summary', $declared[MonitorEntityTypes::ISSUE] ?? []);
        self::assertContains('what_is_asked', $declared[MonitorEntityTypes::ISSUE] ?? []);
        self::assertContains('statement', $declared[MonitorEntityTypes::PORTAL_ACCESS_STATE] ?? []);
    }

    public function testEveryDataDeclaredFieldIsAPhysicalColumnWithNoBlob(): void
    {
        foreach ($this->dataDeclaredFields() as $typeId => $fields) {
            $columns = $this->columns($typeId);
            self::assertNotContains('_data', $columns, $typeId . ' is sql-column backed and has no blob');

            foreach ($fields as $field) {
                self::assertContains($field, $columns, sprintf('%s.%s must be materialised', $typeId, $field));
            }
        }
    }

    public function testTheIssueSummaryAndAskSurviveSaveAndReload(): void
    {
        $repository = $this->kernel()->getEntityTypeManager()->getRepository(MonitorEntityTypes::ISSUE);

        $issue = $repository->create([
            'slug' => 'records-request',
            'title' => 'Records request awaiting response',
            'issue_state' => 'open',
            'opened_at' => 1_700_000_000,
            'summary' => 'A records request was submitted and has not received a response.',
            'what_is_asked' => 'A written response naming which records will be released and when.',
        ]);
        $repository->save($issue, validate: false);
        $id = (string) $issue->id();

        $fresh = $repository->find($id);
        self::assertNotNull($fresh);
        self::assertSame(
            'A records request was submitted and has not received a response.',
            $fresh->get('summary'),
            'the summary was NULL on alpha.282',
        );
        self::assertSame(
            'A written response naming which records will be released and when.',
            $fresh->get('what_is_asked'),
        );

        // And physically, not merely through the accessor.
        self::assertStringContainsString('records request was submitted', (string) $this->rawColumn('monitor_issue', 'summary', $id));
    }

    public function testTheIssueSummarySurvivesAnUpdate(): void
    {
        $repository = $this->kernel()->getEntityTypeManager()->getRepository(MonitorEntityTypes::ISSUE);

        $issue = $repository->create(['slug' => 's', 'title' => 't', 'issue_state' => 'open', 'summary' => 'first']);
        $repository->save($issue, validate: false);
        $id = (string) $issue->id();

        $loaded = $repository->find($id);
        self::assertNotNull($loaded);
        $loaded->set('summary', 'second');
        $repository->save($loaded, validate: false);

        self::assertSame('second', $repository->find($id)?->get('summary'));
        self::assertSame('second', $this->rawColumn('monitor_issue', 'summary', $id));
    }

    public function testThePortalStatementSurvivesSaveAndReload(): void
    {
        $repository = $this->kernel()->getEntityTypeManager()->getRepository(MonitorEntityTypes::PORTAL_ACCESS_STATE);
        $statement = 'The members portal is access-controlled. This monitor does not attempt to reach it.';

        $state = $repository->create([
            'state' => 'access_controlled',
            'verified_on' => 'July 2026',
            'statement' => $statement,
            'verified_by_role' => 'member reviewer',
        ]);
        $repository->save($state, validate: false);

        self::assertSame($statement, $repository->find((string) $state->id())?->get('statement'));
    }

    public function testTheProjectionEmitsTheDataDeclaredValues(): void
    {
        // The end the user actually sees: an empty paragraph on the dashboard is
        // how this defect was found, so assert the projection carries the value.
        $manager = $this->kernel()->getEntityTypeManager();
        $repository = $manager->getRepository(MonitorEntityTypes::ISSUE);

        $issue = $repository->create([
            'slug' => 'p', 'title' => 't', 'issue_state' => 'open',
            'summary' => 'visible summary', 'what_is_asked' => 'visible ask',
        ]);
        $repository->save($issue, validate: false);

        $view = new SagamokMonitorRepository($manager)->view($repository->find((string) $issue->id()));

        self::assertSame('visible summary', $view['summary']);
        self::assertSame('visible ask', $view['what_is_asked']);
    }

    public function testADataDeclaredFieldIsFilterable(): void
    {
        // The query half of #2165: json_extract(_data, ...) against a table with
        // no blob. Exercised through the repository, as a listing would.
        $repository = $this->kernel()->getEntityTypeManager()->getRepository(MonitorEntityTypes::ISSUE);

        foreach ([['a', 'alpha'], ['b', 'beta'], ['c', 'alpha']] as [$slug, $summary]) {
            $issue = $repository->create(['slug' => $slug, 'title' => $slug, 'issue_state' => 'open', 'summary' => $summary]);
            $repository->save($issue, validate: false);
        }

        $slugs = [];
        foreach ($repository->findBy(['summary' => 'alpha']) as $match) {
            $slugs[] = $match->get('slug');
        }
        sort($slugs);

        self::assertSame(['a', 'c'], $slugs);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['name'],
            $this->pdo()->query("PRAGMA table_info({$table})")->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    private function rawColumn(string $table, string $column, string $id): mixed
    {
        $statement = $this->pdo()->prepare("SELECT {$column} AS v FROM {$table} WHERE id = ?");
        $statement->execute([$id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row['v'];
    }

    private function pdo(): \PDO
    {
        return new \PDO('sqlite:' . $this->databasePath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
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
