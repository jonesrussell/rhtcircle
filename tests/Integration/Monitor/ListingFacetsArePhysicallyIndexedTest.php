<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Monitor\MonitorEntityTypes;
use App\Provider\SagamokMonitorServiceProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Every field a monitor listing filters or sorts on must be a real SQLite
 * column carrying a real index — proven by introspecting the schema that
 * `db:init` actually produced, not by re-reading the declarations.
 *
 * This test exists because the declaration and the physical shape were allowed
 * to diverge silently. Listing Rule G validates `FieldStorage::Column` on the
 * field *definition*; before framework #2157 an attribute-defined entity type
 * could not select the sql-column backend at all, so every such field passed
 * Rule G, landed in the `_data` JSON blob, and was filtered over the blob with
 * no index and nothing anywhere to say so. Re-asserting the attributes here
 * would reproduce exactly that blind spot, so every assertion below reads
 * PRAGMA output.
 *
 * The required field set is DERIVED from the registered ListingDefinitions
 * rather than hardcoded: a listing that gains a facet must gain an index, and
 * this test must fail until it does.
 */
final class ListingFacetsArePhysicallyIndexedTest extends TestCase
{
    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-monitor-index-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET'] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=monitor-index-test-secret');

        $this->runCli('db:init');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    // ------------------------------------------------------------------
    // The facet set, derived from the registered listings
    // ------------------------------------------------------------------

    /**
     * Every (entity type id => list of field names) a monitor listing filters
     * or sorts on, read from the ListingDefinitions themselves.
     *
     * @return array<string, list<string>>
     */
    private function requiredFacets(): array
    {
        $facets = [];

        foreach (new SagamokMonitorServiceProvider()->listings() as $listing) {
            foreach ($listing->filters as $filter) {
                $facets[$listing->entityType][] = $filter->field;
            }
            foreach ($listing->sorts as $sort) {
                $facets[$listing->entityType][] = $sort->field;
            }
        }

        foreach ($facets as $type => $fields) {
            $facets[$type] = array_values(array_unique($fields));
            sort($facets[$type]);
        }
        ksort($facets);

        return $facets;
    }

    public function testTheFacetSetIsNonEmptyAndCoversTheListedTypes(): void
    {
        // Guards the derivation itself: if listings() ever returns [] or the
        // filter/sort accessors are renamed, every other assertion in this file
        // would vacuously pass while proving nothing.
        $facets = $this->requiredFacets();

        self::assertNotEmpty($facets, 'no facets were derived — the derivation is broken, not the schema');

        $total = array_sum(array_map('count', $facets));
        self::assertGreaterThanOrEqual(
            10,
            $total,
            'the dashboard listings declare more facets than this; the derivation is under-reading them',
        );

        foreach (array_keys($facets) as $type) {
            self::assertContains($type, MonitorEntityTypes::ALL, "$type is not a monitor entity type");
        }
    }

    // ------------------------------------------------------------------
    // Physical column + physical index, from PRAGMA
    // ------------------------------------------------------------------

    public function testEveryFacetIsAPhysicalColumn(): void
    {
        foreach ($this->requiredFacets() as $type => $fields) {
            $columns = $this->columns($type);

            self::assertNotContains(
                '_data',
                $columns,
                "$type still has a _data blob — it did not resolve to the sql-column backend",
            );

            foreach ($fields as $field) {
                self::assertContains(
                    $field,
                    $columns,
                    "$type.$field is filtered or sorted on but is not a physical column (columns: "
                    . implode(', ', $columns) . ')',
                );
            }
        }
    }

    public function testEveryFacetHasASupportingIndex(): void
    {
        foreach ($this->requiredFacets() as $type => $fields) {
            $covered = $this->indexedColumns($type);

            foreach ($fields as $field) {
                self::assertArrayHasKey(
                    $field,
                    $covered,
                    "$type.$field is filtered or sorted on but no index covers it (indexed columns: "
                    . implode(', ', array_keys($covered)) . ')',
                );
            }
        }
    }

    public function testTheSupportingIndexLeadsWithTheFacetColumn(): void
    {
        // An index whose FIRST column is something else does not support a
        // filter or sort on this field — SQLite cannot seek into it. Asserting
        // mere membership would accept a useless composite.
        foreach ($this->requiredFacets() as $type => $fields) {
            foreach ($fields as $field) {
                $leading = [];
                foreach ($this->indexes($type) as $index) {
                    $columns = $this->indexColumns($index);
                    if (($columns[0] ?? null) === $field) {
                        $leading[] = $index;
                    }
                }

                self::assertNotEmpty(
                    $leading,
                    "$type.$field has no index that LEADS with it, so no filter or sort can use one",
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // No Internal field may be a facet
    // ------------------------------------------------------------------

    public function testNoInternalFieldIsUsedAsAFacet(): void
    {
        // Two independent reasons, either sufficient:
        //   1. Reading an Internal field throws without an audited capability,
        //      so a listing that filters or sorts on one is a runtime failure
        //      waiting for its first row.
        //   2. Spec §6.2: `severity` and `answers_ask` are Internal precisely so
        //      they cannot order or select a public list. An ordering IS a
        //      disclosure — a public list sorted by severity publishes the
        //      severity ranking without ever rendering the field.
        $manager = $this->bootedKernel()->getEntityTypeManager();
        $registry = $manager->getFieldRegistry();

        foreach ($this->requiredFacets() as $type => $fields) {
            $definitions = $registry->coreFieldsFor($type);

            foreach ($fields as $field) {
                self::assertArrayHasKey($field, $definitions, "$type.$field is not a registered field");

                self::assertNotSame(
                    FieldReadLevel::Internal,
                    $definitions[$field]->getReadLevel(),
                    "$type.$field is Internal and must never be a listing facet",
                );
            }
        }
    }

    public function testTheInternalEditorialJudgementFieldsAreNotFacets(): void
    {
        // Named explicitly, so deleting the generic check above cannot quietly
        // reopen the two fields the spec calls out by name.
        $facets = $this->requiredFacets();

        self::assertNotContains('severity', $facets[MonitorEntityTypes::ISSUE] ?? []);
        self::assertNotContains('answers_ask', $facets[MonitorEntityTypes::OFFICIAL_UPDATE] ?? []);
    }

    // ------------------------------------------------------------------
    // Idempotence
    // ------------------------------------------------------------------

    public function testRepeatedDbInitDoesNotChangeOrDuplicateTheIndexSet(): void
    {
        $before = $this->indexInventory();

        $this->runCli('db:init');
        $this->runCli('db:init');

        $after = $this->indexInventory();

        self::assertSame($before, $after, 'repeated db:init must leave the index set byte-identical');

        // And separately: no table may carry two indexes over the same column
        // list, which is how a non-idempotent sync usually shows up.
        foreach (MonitorEntityTypes::ALL as $type) {
            $seen = [];
            foreach ($this->indexes($type) as $index) {
                $key = implode(',', $this->indexColumns($index));
                self::assertNotContains($key, $seen, "$type has duplicate indexes over ($key)");
                $seen[] = $key;
            }
        }
    }

    // ------------------------------------------------------------------
    // Introspection helpers — every one reads the live SQLite schema
    // ------------------------------------------------------------------

    private function pdo(): \PDO
    {
        return new \PDO('sqlite:' . $this->databasePath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $rows = $this->pdo()->query("PRAGMA table_info({$table})")->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /** @return list<string> */
    private function indexes(string $table): array
    {
        $rows = $this->pdo()->query("PRAGMA index_list({$table})")->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /** @return list<string> */
    private function indexColumns(string $index): array
    {
        $rows = $this->pdo()->query("PRAGMA index_info({$index})")->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /**
     * Column name => list of indexes covering it.
     *
     * @return array<string, list<string>>
     */
    private function indexedColumns(string $table): array
    {
        $covered = [];
        foreach ($this->indexes($table) as $index) {
            foreach ($this->indexColumns($index) as $column) {
                $covered[$column][] = $index;
            }
        }

        return $covered;
    }

    /**
     * A stable, comparable snapshot of every monitor table's index set.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function indexInventory(): array
    {
        $inventory = [];
        foreach (MonitorEntityTypes::ALL as $type) {
            $byIndex = [];
            foreach ($this->indexes($type) as $index) {
                $byIndex[$index] = $this->indexColumns($index);
            }
            ksort($byIndex);
            $inventory[$type] = $byIndex;
        }
        ksort($inventory);

        return $inventory;
    }

    private function bootedKernel(): HttpKernel
    {
        $kernel = new HttpKernel($this->projectRoot);
        $kernel->bootForCli();

        return $kernel;
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
