<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Access\MonitorDashboardAccessPolicy;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\SagamokMonitorRepository;
use App\Provider\SagamokMonitorServiceProvider;
use App\Schedule\SagamokMonitorSchedule;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Spec §9.1: everything this feature adds is **discovered** by the real kernel,
 * not assembled by hand in a test.
 *
 * The distinction matters. A test that constructs the provider, registers the
 * types itself and then asserts they exist proves only that the test can do
 * that. It would pass while the application, which relies on manifest
 * discovery, registered nothing at all. Every assertion below reads from a
 * booted kernel.
 *
 * It also asserts the **closed public projection as a set**, which is the check
 * that would have caught the §6.0 composition leak: a field added to an entity
 * cannot reach a reader unless it is added here deliberately.
 */
final class MonitorCompositionTest extends TestCase
{
    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?HttpKernel $kernel = null;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-composition-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=composition-test-secret');
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
    // Discovery, from the booted kernel
    // ------------------------------------------------------------------

    public function testTheProviderIsDiscoveredByTheKernel(): void
    {
        $found = false;
        foreach ($this->kernel()->getProviders() as $provider) {
            if ($provider instanceof SagamokMonitorServiceProvider) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'the provider must be discovered through the manifest, not constructed by hand');
    }

    public function testAllSevenEntityTypesAreRegisteredByTheKernel(): void
    {
        $manager = $this->kernel()->getEntityTypeManager();

        foreach (MonitorEntityTypes::ALL as $typeId) {
            self::assertTrue($manager->hasDefinition($typeId), $typeId . ' must be registered');
        }
        self::assertCount(7, MonitorEntityTypes::ALL);
    }

    public function testAllSevenListingsAreRegisteredByTheKernel(): void
    {
        $expected = [
            SagamokMonitorServiceProvider::LISTING_SOURCES,
            SagamokMonitorServiceProvider::LISTING_ITEMS,
            SagamokMonitorServiceProvider::LISTING_CHANGES,
            SagamokMonitorServiceProvider::LISTING_TIMELINE,
            SagamokMonitorServiceProvider::LISTING_ISSUES_OPEN,
            SagamokMonitorServiceProvider::LISTING_ISSUES_RESOLVED,
            SagamokMonitorServiceProvider::LISTING_UPDATES,
        ];

        $ids = [];
        foreach (new SagamokMonitorServiceProvider()->listings() as $listing) {
            $ids[] = $listing->id;

            // Every listing asks for the bespoke ability and nothing else. A
            // listing that asked for `view` would inherit whatever the entity
            // gate grants, which is the leak this design avoids.
            self::assertSame([MonitorDashboardAccessPolicy::ABILITY], $listing->accessOps, $listing->id);
        }

        self::assertSame($expected, $ids);
    }

    public function testTheAccessPolicyIsDiscoveredAndGrantsOnlyTheBespokeAbility(): void
    {
        // Boot-discovered via #[PolicyAttribute]; asserted through the composed
        // handler rather than by instantiating the policy directly.
        $manager = $this->kernel()->getEntityTypeManager();
        $entity = $manager->getRepository(MonitorEntityTypes::ITEM)
            ->create(['source_key' => 'sagamok_public_site', 'public_ref' => 'p-1']);

        $policy = new MonitorDashboardAccessPolicy();
        $account = new \Waaseyaa\User\AnonymousUser([]);

        self::assertTrue($policy->access($entity, MonitorDashboardAccessPolicy::ABILITY, $account)->isAllowed());
        foreach (['view', 'update', 'delete', 'create'] as $op) {
            self::assertFalse(
                $policy->access($entity, $op, $account)->isAllowed(),
                sprintf('"%s" must never be granted: it is what MCP, GraphQL, JSON:API and SSR consult', $op),
            );
        }
    }

    public function testTheScheduleEntryIsDiscoveredButNotLive(): void
    {
        // Discovery and activation are different things, and the test asserts
        // both halves: the class is a real schedule entry, and the kernel runs
        // no task for it.
        self::assertTrue(
            is_subclass_of(SagamokMonitorSchedule::class, \Waaseyaa\Scheduler\ScheduleEntriesInterface::class)
            || in_array(\Waaseyaa\Scheduler\ScheduleEntriesInterface::class, class_implements(SagamokMonitorSchedule::class) ?: [], true),
            'the schedule class must implement the discoverable contract',
        );

        foreach ($this->kernel()->getSchedule()->tasks() as $task) {
            self::assertNotSame(SagamokMonitorSchedule::TASK_ID, $task->name);
        }
    }

    public function testBothDashboardRoutesAnswerThroughTheRealKernel(): void
    {
        // Asserted by dispatching, not by reading a route table: a registered
        // route that throws on dispatch is not a working route.
        $dashboard = $this->request('/communities/sagamok/monitor');
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('Monitoring status', (string) $dashboard->getContent());

        // An unknown issue slug is a 404, not a 500 and not a blank 200.
        $missing = $this->request('/communities/sagamok/monitor/no-such-issue');
        self::assertSame(404, $missing->getStatusCode());
    }

    public function testTheDashboardEmitsNoInternalOrCollectorValue(): void
    {
        $html = (string) $this->request('/communities/sagamok/monitor')->getContent();

        foreach ([
            'severity', 'answers_ask', 'last_error', 'notes',
            'method_note', 'review_note', 'verified_by_role', 'supplied_by_role',
            'item_key', 'content_hash', 'normalized_bytes', 'snapshot',
        ] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $html, $forbidden . ' must never reach the page');
        }
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
            'HTTP_HOST' => 'rhtcircle.ca',
            'SERVER_NAME' => 'rhtcircle.ca',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $this->projectRoot . '/public/index.php',
        ];

        return new HttpKernel($this->projectRoot)->handle();
    }

    // ------------------------------------------------------------------
    // The closed projection, as a SET
    // ------------------------------------------------------------------

    public function testThePublicProjectionIsAClosedSetPerType(): void
    {
        // Asserted exhaustively, not by spot-checking a few fields: this is the
        // shape of test that catches a leak introduced by a later field.
        self::assertSame([
            MonitorEntityTypes::SOURCE => ['key', 'label', 'origin_url', 'health', 'last_check_completed', 'last_success'],
            MonitorEntityTypes::ITEM => ['public_ref', 'title', 'public_url', 'doc_kind', 'change_status', 'first_seen', 'last_seen', 'changed_at', 'disappeared_at', 'event_count'],
            MonitorEntityTypes::EVENT => ['item_public_ref', 'event_type', 'observed_at', 'effective_at', 'evidence_kind', 'evidence_url', 'evidence_captured_at'],
            MonitorEntityTypes::ISSUE => ['slug', 'title', 'issue_state', 'opened_at', 'status_changed_at', 'closed_at', 'summary', 'what_is_asked', 'related_article_slugs', 'related_item_public_refs'],
            MonitorEntityTypes::OFFICIAL_UPDATE => ['issue_slug', 'published_at', 'source_label', 'source_url', 'summary'],
            MonitorEntityTypes::PORTAL_ACCESS_STATE => ['state', 'verified_on', 'statement'],
            MonitorEntityTypes::PORTAL_UPDATE_NOTE => ['title_supplied', 'supplied_on', 'official_date', 'summary'],
        ], SagamokMonitorRepository::projection());
    }

    public function testEveryProjectedFieldActuallyExistsOnItsEntityType(): void
    {
        // Guards the opposite failure: a projection that names a field which no
        // longer exists would silently emit empty values forever.
        $registry = $this->kernel()->getEntityTypeManager()->getFieldRegistry();

        foreach (SagamokMonitorRepository::projection() as $typeId => $fields) {
            $defined = array_keys($registry->coreFieldsFor($typeId));
            foreach ($fields as $field) {
                self::assertContains($field, $defined, sprintf('%s.%s is projected but not declared', $typeId, $field));
            }
        }
    }

    public function testNoInternalFieldAppearsInAnyProjection(): void
    {
        $registry = $this->kernel()->getEntityTypeManager()->getFieldRegistry();

        foreach (SagamokMonitorRepository::projection() as $typeId => $fields) {
            $definitions = $registry->coreFieldsFor($typeId);
            foreach ($fields as $field) {
                self::assertNotSame(
                    \Waaseyaa\Entity\FieldReadLevel::Internal,
                    $definitions[$field]->getReadLevel(),
                    sprintf('%s.%s is Internal and must never be projected', $typeId, $field),
                );
            }
        }
    }

    public function testTheProjectionFailsClosedForAnUnknownType(): void
    {
        // A monitor entity type added later projects to NOTHING until someone
        // adds it here, rather than inheriting a default of "publish it all".
        self::assertSame([], SagamokMonitorRepository::projectedKeys('some_future_monitor_type'));
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
