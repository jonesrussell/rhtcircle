<?php

declare(strict_types=1);

namespace App\Tests\Integration\Forms;

use App\Analytics\{AnalyticsRecorder, AnalyticsSchema, CampaignAnalytics, CampaignRegistry, CampaignSchema};
use App\Contact\{ContactRepository, ContactSchema};
use App\Controller\{CollectController, ContactController};
use App\Rendering\{PublicAssetVersioner, SiteRenderer};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\SSR\ThemeServiceProvider;

final class CampaignTrackingTest extends TestCase
{
    private DBALDatabase $db;
    private ContactRepository $contacts;
    private ContactController $controller;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        (new AnalyticsSchema($this->db))->ensure();
        (new ContactSchema($this->db))->ensure();
        (new CampaignSchema($this->db))->ensure();
        $this->contacts = new ContactRepository($this->db, 'test-secret');
        $root = dirname(__DIR__, 3);
        $this->controller = new ContactController($this->contacts, new SiteRenderer(
            ThemeServiceProvider::createTwigEnvironment($root), $root, new PublicAssetVersioner($root . '/public'),
        ));
    }

    private function request(array $overrides = [], array $headers = []): Request
    {
        $request = Request::create('/api/contact', 'POST', content: json_encode(array_replace([
            'name' => 'Synthetic test', 'email' => 'test@example.invalid', 'message' => 'Private message text.',
            'website' => '', 'analytics_enabled' => true, 'campaign' => CampaignRegistry::FACEBOOK,
        ], $overrides), JSON_THROW_ON_ERROR));
        $request->headers->set('User-Agent', 'Mozilla/5.0');
        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }
        return $request;
    }

    private function summary(): array
    {
        return (new CampaignAnalytics($this->db))->summary(gmdate('Y-m-d'), gmdate('Y-m-d'));
    }

    public function testOnlySavedMessagesBecomeConversionsAndContainNoContactData(): void
    {
        self::assertSame(422, $this->controller->submit($this->request(['email' => 'invalid']))->getStatusCode());
        self::assertSame(200, $this->controller->submit($this->request(['website' => 'honeypot']))->getStatusCode());
        self::assertSame(0, $this->contacts->count());
        self::assertSame(0, $this->summary()['contacts']);
        self::assertSame(200, $this->controller->submit($this->request())->getStatusCode());
        self::assertSame(1, $this->contacts->count());
        self::assertSame(1, $this->summary()['contacts']);
        self::assertSame(1, $this->summary()['campaigns'][0]['contacts']);
        $events = iterator_to_array($this->db->query('SELECT * FROM ' . CampaignSchema::TABLE));
        $encoded = json_encode($events, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('test@example.invalid', $encoded);
        self::assertStringNotContainsString('Private message', $encoded);
        self::assertSame(['id', 'event_type', 'campaign_key', 'event_key', 'created_at'], array_keys($events[0]));
    }

    public function testPrivacyOptOutsStillAcceptMessagesWithoutConversions(): void
    {
        foreach ([
            $this->request([], ['DNT' => '1']),
            $this->request([], ['Sec-GPC' => '1']),
            $this->request(['analytics_enabled' => false]),
            $this->request(['analytics_enabled' => 'true']),
            $this->request([], ['User-Agent' => 'facebookexternalhit']),
        ] as $request) {
            self::assertSame(200, $this->controller->submit($request)->getStatusCode());
        }
        self::assertSame(5, $this->contacts->count());
        self::assertSame(0, $this->summary()['contacts']);
        self::assertSame(429, $this->controller->submit($this->request())->getStatusCode());
        self::assertSame(0, $this->summary()['contacts']);
    }

    public function testUnregisteredLabelsAreNeverStoredAndTestsAreExcluded(): void
    {
        $this->controller->submit($this->request(['campaign' => 'private-person@example.invalid']));
        $this->controller->submit($this->request(['campaign' => CampaignRegistry::VERIFICATION]));
        self::assertSame(1, $this->summary()['contacts']);
        self::assertSame(1, $this->summary()['unattributed_contacts']);
        self::assertSame(0, $this->summary()['campaigns'][0]['contacts']);
        $rows = iterator_to_array($this->db->query('SELECT campaign_key FROM ' . CampaignSchema::TABLE));
        self::assertNull($rows[0]['campaign_key']);
        self::assertSame(CampaignRegistry::VERIFICATION, $rows[1]['campaign_key']);
    }

    public function testConversionFailureRollsBackTheContactSave(): void
    {
        $this->db->schema()->dropTable(CampaignSchema::TABLE);
        try {
            $this->controller->submit($this->request());
            self::fail('Expected analytics storage failure.');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertSame(0, $this->contacts->count());
        }
        // Analytics is optional when the client opted out.
        self::assertSame(200, $this->controller->submit($this->request(['analytics_enabled' => false]))->getStatusCode());
    }

    public function testCampaignLandingsDeduplicateAndIgnoreWrongPathsAndBots(): void
    {
        $recorder = new AnalyticsRecorder($this->db, 'secret');
        $beacon = ['t' => 'pageview', 'p' => CampaignRegistry::ELECTION_PATH, 'v' => 'a-random-pageview-id', 'campaign' => CampaignRegistry::FACEBOOK];
        $recorder->record($beacon, '127.0.0.1', 'Mozilla/5.0');
        $recorder->record($beacon, '127.0.0.1', 'Mozilla/5.0');
        $recorder->record(array_replace($beacon, ['v' => 'second-id', 'p' => '/contact']), null, 'Mozilla/5.0');
        $recorder->record(array_replace($beacon, ['v' => 'third-id']), null, 'facebookexternalhit');
        self::assertSame(1, $this->summary()['campaigns'][0]['landings']);
        (new CampaignSchema($this->db))->ensure();
        self::assertSame(1, $this->summary()['campaigns'][0]['landings']);
        self::assertSame([], iterator_to_array($this->db->query("SELECT * FROM " . CampaignSchema::TABLE . " WHERE event_type = 'contact_saved'")));
    }

    public function testPublicCollectorCannotForgeConversionsAndHonoursPrivacyHeaders(): void
    {
        $collector = new CollectController(new AnalyticsRecorder($this->db, 'secret'));
        foreach ([
            ['contact_saved', [], CampaignRegistry::FACEBOOK],
            ['pageview', ['DNT' => '1'], CampaignRegistry::FACEBOOK],
            ['pageview', ['Sec-GPC' => '1'], CampaignRegistry::FACEBOOK],
            ['pageview', ['Origin' => 'https://other.invalid'], CampaignRegistry::FACEBOOK],
        ] as [$type, $headers, $campaign]) {
            $request = Request::create('https://rhtcircle.ca/api/collect', 'POST', content: json_encode([
                't' => $type, 'p' => CampaignRegistry::ELECTION_PATH, 'v' => 'test-pageview', 'campaign' => $campaign,
            ], JSON_THROW_ON_ERROR));
            foreach ($headers as $key => $value) {
                $request->headers->set($key, $value);
            }
            self::assertSame(204, $collector->collect($request)->getStatusCode());
        }
        self::assertSame(0, $this->summary()['contacts']);
        self::assertSame(0, $this->summary()['campaigns'][0]['landings']);
    }

    public function testAdditiveInitializationPreservesExistingContactAndAnalyticsRows(): void
    {
        $this->controller->submit($this->request(['analytics_enabled' => false]));
        (new AnalyticsRecorder($this->db, 'secret'))->record(['t' => 'pageview', 'p' => '/about', 'v' => 'legacy-view'], null, null);
        $this->db->schema()->dropTable(CampaignSchema::TABLE);
        (new CampaignSchema($this->db))->ensure();
        (new CampaignSchema($this->db))->ensure();
        self::assertSame(1, $this->contacts->count());
        self::assertCount(1, iterator_to_array($this->db->query('SELECT id FROM analytics_event')));
        self::assertSame(0, $this->summary()['contacts']);
    }
}
