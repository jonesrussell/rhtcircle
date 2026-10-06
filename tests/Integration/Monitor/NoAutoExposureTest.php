<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Access\MonitorDashboardAccessPolicy;
use App\Monitor\MonitorEntityTypes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Spec §9.2: the auto-exposure lockdown, asserted route by route.
 *
 * Four framework surfaces are generic over ANY registered entity type and need
 * no opt-in — MCP `entity.read`/`entity.search`, GraphQL, `/api/discovery/*`,
 * and the SSR `/{type}/{id}` catch-all — and for all four the only gate is the
 * entity access policy. This test is written and passing BEFORE the collector
 * exists, because the boundary is cheap to assert and expensive to discover
 * late.
 *
 * Two mechanisms that look like protection and are NOT, so neither is asserted
 * here or may be cited in review: `discoverable: false` gates only the
 * `GET /api` discovery document, and the entity-type disable mechanism is
 * consulted by zero read paths.
 */
final class NoAutoExposureTest extends TestCase
{
    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-monitor-exposure-' . bin2hex(random_bytes(8)) . '.sqlite';

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=monitor-exposure-test-secret');
        // A dev-fallback account masks field-read denials and would invalidate
        // every assertion below.
        putenv('WAASEYAA_DEV_FALLBACK_ACCOUNT=false');

        $this->runCli('db:init');
        $this->seedOneRowPerType();
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
    // Declarative: the three registration rules that make the rest hold
    // ------------------------------------------------------------------

    public function testNoMonitorTypeEnablesJsonApi(): void
    {
        foreach (MonitorEntityTypes::CLASSES as $id => $class) {
            $attribute = (new \ReflectionClass($class))->getAttributes(ContentEntityType::class)[0] ?? null;
            self::assertNotNull($attribute, "$id must declare #[ContentEntityType]");

            /** @var ContentEntityType $instance */
            $instance = $attribute->newInstance();
            self::assertFalse(
                $instance->api,
                "$id must not set api: true — that is the only way to generate JSON:API routes",
            );
        }
    }

    public function testNoMonitorTypeUsesTheContentGroup(): void
    {
        // This is the load-bearing one and the easiest mistake to make, because
        // the app's existing types DO use group: 'content'. That flag is an
        // affirmative grant of anonymous read via the kernel's unconditional
        // PublishedContentAccessPolicy, opening MCP, SSR, GraphQL and Discovery
        // at once.
        $manager = $this->bootedKernel()->getEntityTypeManager();

        foreach (MonitorEntityTypes::ALL as $id) {
            $definition = $manager->getDefinition($id);
            self::assertNotSame(
                'content',
                $definition->getGroup(),
                "$id must not be in the 'content' group: that grants anonymous view",
            );
        }
    }

    public function testNoMonitorTypeDeclaresAStatusField(): void
    {
        // Without a `status` field WorkflowVisibility::isEntityPublic() can
        // never be true, so the Discovery routes stay closed even if a future
        // policy changes.
        foreach (MonitorEntityTypes::CLASSES as $id => $class) {
            $properties = array_map(
                static fn (\ReflectionProperty $p): string => $p->getName(),
                (new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC),
            );
            self::assertNotContains('status', $properties, "$id must not declare a status field");
        }
    }

    public function testThePolicyGrantsOnlyTheBespokeAbility(): void
    {
        $policy = new MonitorDashboardAccessPolicy();
        $entity = $this->anyMonitorEntity();
        $account = $this->anonymousAccount();

        self::assertTrue(
            $policy->access($entity, MonitorDashboardAccessPolicy::ABILITY, $account)->isAllowed(),
            'the dashboard listings depend on this ability being granted',
        );

        foreach (['view', 'update', 'delete', 'view_revision'] as $operation) {
            self::assertFalse(
                $policy->access($entity, $operation, $account)->isAllowed(),
                "granting '$operation' would open MCP, GraphQL, Discovery and SSR at once",
            );
        }

        self::assertFalse(
            $policy->createAccess(MonitorEntityTypes::ITEM, '', $account)->isAllowed(),
        );
    }

    public function testThePolicyDoesNotOptIntoTheListingFastPath(): void
    {
        // Belt-and-braces rather than load-bearing: ListingResolver skips the
        // fast path whenever accessOps differs from the default ['view'],
        // before it ever probes this constant. Asserted because it costs
        // nothing and documents intent.
        self::assertFalse(
            \defined(MonitorDashboardAccessPolicy::class . '::SUPPORTS_LISTING_FAST_PATH'),
            'the monitor policy must not opt into skipping the per-row access gate',
        );
    }

    // ------------------------------------------------------------------
    // Route by route, anonymously
    // ------------------------------------------------------------------

    public function testJsonApiRoutesAreAbsentForEveryMonitorType(): void
    {
        foreach (MonitorEntityTypes::ALL as $id) {
            $response = $this->request('/api/' . $id);
            self::assertSame(404, $response->getStatusCode(), "/api/$id must not exist");
        }
    }

    public function testSsrEntityRouteDoesNotServeMonitorEntities(): void
    {
        foreach ($this->seededIds() as $id => $rowId) {
            $response = $this->request('/' . $id . '/' . $rowId);
            self::assertNotSame(
                200,
                $response->getStatusCode(),
                "GET /$id/$rowId must not serve a monitor entity",
            );
        }
    }

    public function testDiscoveryRoutesDenyEveryMonitorType(): void
    {
        foreach ($this->seededIds() as $id => $rowId) {
            foreach (['hub', 'cluster', 'timeline', 'endpoint'] as $kind) {
                $response = $this->request("/api/discovery/$kind/$id/$rowId");
                self::assertNotSame(
                    200,
                    $response->getStatusCode(),
                    "/api/discovery/$kind/$id/$rowId must be denied",
                );
            }
        }
    }

    public function testTheGateEveryGenericSurfaceConsultsDeniesAnonymousView(): void
    {
        // MCP entity.read/entity.search, GraphQL's GraphQlAccessGuard::canView(),
        // /api/discovery/* and the SSR catch-all all reduce to exactly this
        // check. Asserting it against the REAL kernel-wired EntityAccessHandler
        // (not the policy in isolation) proves the composition: no other
        // registered policy — including the kernel's unconditional
        // PublishedContentAccessPolicy — grants view to a monitor type.
        //
        // Those four surfaces are POST- or catch-all-driven and cannot be given
        // a raw request body in-process, so the HTTP layer is proven separately
        // against a real server in the production-shaped rehearsal
        // (scripts/monitor-rehearsal.sh).
        $kernel = $this->bootedKernel();
        $handler = $kernel->getAccessHandler();
        $manager = $kernel->getEntityTypeManager();
        $account = $this->anonymousAccount();

        foreach ($this->seededIds() as $type => $rowId) {
            $entity = $manager->getRepository($type)->find((string) $rowId);
            self::assertNotNull($entity, "fixture for $type should load for the collector's own use");

            foreach (['view', 'update', 'delete'] as $operation) {
                self::assertFalse(
                    $handler->check($entity, $operation, $account)->isAllowed(),
                    "anonymous '$operation' on $type must be denied: that is the only gate MCP, GraphQL, Discovery and SSR apply",
                );
            }

            // And the positive control on the same handler, so the test proves
            // a working boundary rather than a broken entity.
            self::assertTrue(
                $handler->check($entity, MonitorDashboardAccessPolicy::ABILITY, $account)->isAllowed(),
                "the dashboard ability must resolve on $type or the listings return nothing",
            );
        }
    }

    // ------------------------------------------------------------------
    // Positive control
    // ------------------------------------------------------------------

    public function testTheDashboardListingsStillResolve(): void
    {
        // Without this, a completely broken feature would pass every assertion
        // above. The boundary must be closed AND the dashboard must work.
        $kernel = $this->bootedKernel();
        $provider = null;
        foreach ($kernel->getProviders() as $candidate) {
            if ($candidate instanceof \App\Provider\SagamokMonitorServiceProvider) {
                $provider = $candidate;
                break;
            }
        }
        self::assertNotNull($provider, 'the monitor provider must be registered');

        $definitions = $provider->resolve(\Waaseyaa\Listing\ListingDefinitionRegistry::class);
        $resolver = $provider->resolve(\Waaseyaa\Listing\ListingResolver::class);
        self::assertInstanceOf(\Waaseyaa\Listing\ListingDefinitionRegistry::class, $definitions);
        self::assertInstanceOf(\Waaseyaa\Listing\ListingResolver::class, $resolver);

        $rows = $resolver->resolve($definitions->get(\App\Provider\SagamokMonitorServiceProvider::LISTING_ITEMS))->rows;
        $count = 0;
        foreach ($rows as $_) {
            $count++;
        }
        self::assertGreaterThan(0, $count, 'the monitor listings must resolve rows for the dashboard');
    }

    public function testEveryMonitorListingUsesTheBespokeAbilityAndNoInternalFacet(): void
    {
        $provider = new \App\Provider\SagamokMonitorServiceProvider();
        $internal = ['severity', 'answers_ask', 'last_error', 'notes', 'method_note', 'review_note'];

        foreach ($provider->listings() as $definition) {
            self::assertSame(
                [MonitorDashboardAccessPolicy::ABILITY],
                $definition->accessOps,
                "{$definition->id} must ask only for the bespoke ability",
            );

            foreach ($definition->filters as $filter) {
                self::assertNotContains($filter->field, $internal, "{$definition->id} filters on an Internal field");
                self::assertNull($filter->exposedParam, "{$definition->id} must not expose a request-driven filter");
            }
            foreach ($definition->sorts as $sort) {
                self::assertNotContains($sort->field, $internal, "{$definition->id} sorts on an Internal field");
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private const string SENTINEL = 'ZZSENTINELMONITORVALUE';

    /** @var array<string, int|string> */
    private array $seeded = [];

    /** @return array<string, int|string> */
    private function seededIds(): array
    {
        return $this->seeded;
    }

    private function seedOneRowPerType(): void
    {
        $kernel = $this->bootedKernel();
        $manager = $kernel->getEntityTypeManager();

        $payloads = [
            MonitorEntityTypes::SOURCE => ['key' => 'sagamok_public_site', 'label' => self::SENTINEL, 'origin_url' => 'https://example.invalid/', 'enabled' => true, 'health' => 'ok'],
            MonitorEntityTypes::ITEM => ['source_key' => 'sagamok_public_site', 'public_ref' => 'p-1', 'title' => self::SENTINEL, 'public_url' => 'https://example.invalid/a'],
            MonitorEntityTypes::EVENT => ['source_key' => 'sagamok_public_site', 'item_public_ref' => 'p-1', 'event_type' => 'appeared', 'evidence_url' => self::SENTINEL],
            MonitorEntityTypes::ISSUE => ['slug' => 'seeded-issue', 'title' => self::SENTINEL, 'issue_state' => 'open'],
            MonitorEntityTypes::OFFICIAL_UPDATE => ['issue_slug' => 'seeded-issue', 'source_label' => self::SENTINEL],
            MonitorEntityTypes::PORTAL_ACCESS_STATE => ['state' => 'access_controlled', 'statement' => self::SENTINEL, 'verified_by_role' => 'member reviewer'],
            MonitorEntityTypes::PORTAL_UPDATE_NOTE => ['title_supplied' => self::SENTINEL, 'supplied_by_role' => 'member'],
        ];

        foreach ($payloads as $type => $values) {
            $repository = $manager->getRepository($type);
            $entity = $repository->create($values);
            $repository->save($entity);
            $this->seeded[$type] = $entity->id() ?? 1;
        }
    }

    private function anyMonitorEntity(): \Waaseyaa\Entity\EntityInterface
    {
        return $this->bootedKernel()->getEntityTypeManager()
            ->getRepository(MonitorEntityTypes::ITEM)
            ->create(['source_key' => 'sagamok_public_site', 'public_ref' => 'p-1']);
    }

    private function anonymousAccount(): \Waaseyaa\Access\AccountInterface
    {
        return new \Waaseyaa\User\AnonymousUser([]);
    }

    private function bootedKernel(): HttpKernel
    {
        $kernel = new HttpKernel($this->projectRoot);
        $kernel->bootForCli();

        return $kernel;
    }

    private function request(string $uri): Response
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
