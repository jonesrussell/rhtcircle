<?php

declare(strict_types=1);

namespace App\Tests\Integration\Forms;

use App\Contact\ContactRepository;
use App\Contact\ContactSchema;
use App\Controller\ContactController;
use App\Controller\SignupController;
use App\Poll\PollRepository;
use App\Poll\PollSchema;
use App\Rendering\PublicAssetVersioner;
use App\Rendering\SiteRenderer;
use App\Signup\SignupRepository;
use App\Signup\SignupSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\SSR\ThemeServiceProvider;

final class PublicSubmissionTest extends TestCase
{
    private function renderer(): SiteRenderer
    {
        $root = \dirname(__DIR__, 3);

        return new SiteRenderer(ThemeServiceProvider::createTwigEnvironment($root), $root, new PublicAssetVersioner($root . '/public'));
    }

    public function testStringFalseCannotCreateAConsentingSubscriber(): void
    {
        $db = DBALDatabase::createSqlite();
        new SignupSchema($db)->ensure();
        $repository = new SignupRepository($db, 'test-only-hash-key');
        $controller = new SignupController($repository, $this->renderer());
        $response = $controller->submit(Request::create('/api/signup', 'POST', content: '{"email":"test@example.invalid","consent":"false"}'));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $repository->confirmedCount());

        $accepted = $controller->submit(Request::create('/api/signup', 'POST', content: '{"email":"test@example.invalid","consent":true}'));
        self::assertSame(200, $accepted->getStatusCode());
        self::assertSame(1, $repository->confirmedCount());
    }

    public function testStructuredContactFieldsAreRejectedWithoutStoringAMessage(): void
    {
        $db = DBALDatabase::createSqlite();
        new ContactSchema($db)->ensure();
        $repository = new ContactRepository($db, 'test-only-hash-key');
        $controller = new ContactController($repository, $this->renderer());
        $response = $controller->submit(Request::create('/api/contact', 'POST', content: '{"name":["Test"],"email":"test@example.invalid","message":"Test"}'));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $repository->count());
    }

    public function testPollInitializationKeepsOptionsAndVotesOnRepeatedSetup(): void
    {
        $db = DBALDatabase::createSqlite();
        new PollSchema($db)->ensure();
        $repository = new PollRepository($db, 'test-only-hash-key');
        $repository->ensurePoll('test', 'Test question', ['Yes', 'No']);
        $poll = $repository->findPoll('test');
        self::assertNotNull($poll);
        $id = (int) $poll['id'];
        $options = $repository->options($id);
        self::assertCount(2, $options);
        $repository->castVote((int) $options[0]['id']);
        $repository->ensurePoll('test', 'Changed question', ['Changed']);
        self::assertCount(2, $repository->options($id));
        self::assertSame(1, $repository->results($id)['total']);
    }
}
