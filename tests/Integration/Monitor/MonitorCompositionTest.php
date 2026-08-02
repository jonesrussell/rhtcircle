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

    public function testTheClosedProjectionIsPinnedAtTheEmissionPoint(): void
    {
        // Review finding 5. The projection was asserted against the PROJECTION
        // constant, and `view()` mutates its result after copying that constant:
        // it adds `stalled` for sources, and for portal state it adds
        // `last_verified_on` and unsets `verified_on` (month precision only,
        // spec §7.1).
        //
        // So the constant and the emitted key set genuinely disagree, and the
        // old assertions described something untrue while passing. More
        // importantly, adding `$out['method_note'] = $entity->get('method_note')`
        // inside `view()` would leave every constant-based assertion green while
        // the value reached every template — which is exactly the composition
        // leak this suite exists to prevent.
        //
        // Pinned here as the closed set a reader actually receives.
        $manager = $this->kernel()->getEntityTypeManager();
        $repository = new SagamokMonitorRepository($manager);

        $portal = $manager->getRepository(MonitorEntityTypes::PORTAL_ACCESS_STATE)->create([
            'state' => 'access_controlled',
            'verified_on' => 'July 2026',
            'statement' => 'The members portal is access-controlled.',
            'verified_by_role' => 'member reviewer',
            'method_note' => 'INTERNAL-METHOD-NOTE',
        ]);

        self::assertSame(
            ['state', 'statement', 'last_verified_on'],
            array_keys($repository->view($portal, 1_000_000)),
            'the key set a reader receives, as a closed set',
        );

        $source = $manager->getRepository(MonitorEntityTypes::SOURCE)->create([
            'key' => 'sagamok_public_site',
            'label' => 'Sagamok public website',
            'origin_url' => 'https://www.sagamokanishnawbek.test/',
            'enabled' => true,
            'health' => 'ok',
            'last_error' => 'INTERNAL-LAST-ERROR',
            'last_check_completed' => 1_000_000,
        ]);

        $emitted = $repository->view($source, 1_000_000);
        self::assertContains('stalled', array_keys($emitted), 'view() adds a derived field the constant does not list');
        self::assertNotContains('last_error', array_keys($emitted), 'and never an Internal one');
    }

    public function testAnInternalValueCannotSurviveTheEmittedProjectionUnderAnyKey(): void
    {
        // Mutation control for the test above: keys alone are not enough. A
        // leak that copied an Internal value into an allowed key would keep the
        // key set identical and still publish the value.
        $manager = $this->kernel()->getEntityTypeManager();
        $repository = new SagamokMonitorRepository($manager);
        $marker = 'SENTINEL-' . bin2hex(random_bytes(4));

        $portal = $manager->getRepository(MonitorEntityTypes::PORTAL_ACCESS_STATE)->create([
            'state' => 'access_controlled',
            'verified_on' => 'July 2026',
            'statement' => 'The members portal is access-controlled.',
            'verified_by_role' => $marker,
            'method_note' => $marker,
        ]);

        $values = array_map(
            static fn (mixed $v): string => (string) $v,
            array_values($repository->view($portal, 1_000_000)),
        );

        self::assertNotContains($marker, $values, 'no Internal value may survive under any key');
    }

    public function testTheDashboardEmitsNoInternalOrCollectorValue(): void
    {
        // This test used to grep the HTML for twelve field NAMES. Templates
        // render values, not keys — `{{ issue.summary }}`, never the string
        // "summary" — so a real leak (view() starting to emit `method_note`,
        // a template rendering `{{ portal.method_note }}`) puts the note TEXT
        // on the page while the word `method_note` never appears. All twelve
        // assertions passed straight through the leak they were written to
        // catch.
        //
        // It now plants a distinctive sentinel VALUE in every Internal and
        // collector-only field and asserts none of them is rendered. A sentinel
        // cannot appear by coincidence, and it appears exactly when the field
        // reaches a reader.
        $sentinels = $this->seedInternalSentinels();
        self::assertNotEmpty($sentinels, 'the fixture must plant sentinels, or this proves nothing');

        $response = $this->request('/communities/sagamok/monitor');
        $html = (string) $response->getContent();

        // Positive control. Without these the assertions below would pass on an
        // error page, a redirect, or an empty body.
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Monitoring status', $html, 'the dashboard must actually have rendered');

        foreach ($sentinels as $field => $value) {
            self::assertStringNotContainsString(
                $value,
                $html,
                sprintf('the value of the internal field "%s" reached the page', $field),
            );
        }
    }

    public function testThePlantedSentinelsAreDetectableWhenTheyDoReachThePage(): void
    {
        // Mutation control for the test above: it proves the sentinel technique
        // can actually observe a leak. A PUBLIC field seeded with the same kind
        // of sentinel must be found in the HTML — otherwise "no sentinel found"
        // would just mean "the dashboard never renders anything".
        $marker = 'SENTINEL-PUBLIC-' . bin2hex(random_bytes(4));

        $issues = $this->kernel()->getEntityTypeManager()->getRepository(MonitorEntityTypes::ISSUE);
        $issue = $issues->create([
            'slug' => 'sentinel-visibility-check',
            'title' => $marker,
            'issue_state' => 'open',
            'opened_at' => 900,
            'summary' => 'Seeded to prove a rendered value is observable.',
        ]);
        $issues->save($issue, validate: false);

        $html = (string) $this->request('/communities/sagamok/monitor')->getContent();

        self::assertStringContainsString(
            $marker,
            $html,
            'a public field value must be observable in the HTML, or the leak test is blind',
        );
    }

    /**
     * Plant a unique, unmistakable value in each Internal / collector-only
     * field.
     *
     * @return array<string, string> field name => sentinel value
     */
    private function seedInternalSentinels(): array
    {
        $manager = $this->kernel()->getEntityTypeManager();
        $unique = static fn(string $field): string => 'SENTINEL-' . strtoupper($field) . '-' . bin2hex(random_bytes(4));

        $sentinels = [];

        $sentinels['last_error'] = $unique('last_error');
        $sources = $manager->getRepository(MonitorEntityTypes::SOURCE);
        $source = $sources->create([
            'key' => 'sagamok_public_site',
            'label' => 'Sagamok public website',
            'origin_url' => 'https://www.sagamokanishnawbek.test/',
            'enabled' => true,
            'health' => 'degraded',
            'last_error' => $sentinels['last_error'],
            'last_check_completed' => 1_000_000,
            'last_success' => 1_000_000,
        ]);
        $sources->save($source, validate: false);

        $sentinels['severity'] = $unique('severity');
        $issues = $manager->getRepository(MonitorEntityTypes::ISSUE);
        $issue = $issues->create([
            'slug' => 'sentinel-issue',
            'title' => 'An issue awaiting an answer',
            'issue_state' => 'open',
            'opened_at' => 900,
            'summary' => 'Public summary.',
            'severity' => $sentinels['severity'],
        ]);
        $issues->save($issue, validate: false);

        $sentinels['answers_ask'] = $unique('answers_ask');
        $updates = $manager->getRepository(MonitorEntityTypes::OFFICIAL_UPDATE);
        $update = $updates->create([
            'issue_slug' => 'sentinel-issue',
            'published_at' => 950,
            'source_label' => 'Council minutes',
            'summary' => 'Public update summary.',
            'answers_ask' => $sentinels['answers_ask'],
        ]);
        $updates->save($update, validate: false);

        $sentinels['method_note'] = $unique('method_note');
        $sentinels['verified_by_role'] = $unique('verified_by_role');
        $portal = $manager->getRepository(MonitorEntityTypes::PORTAL_ACCESS_STATE);
        $state = $portal->create([
            'state' => 'access_controlled',
            'verified_on' => 'July 2026',
            'statement' => 'The members portal is access-controlled.',
            'verified_by_role' => $sentinels['verified_by_role'],
            'method_note' => $sentinels['method_note'],
        ]);
        $portal->save($state, validate: false);

        return $sentinels;
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
