<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Analytics\CampaignRegistry;
use App\Contact\ContactRepository;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/** The real application routes, framework middleware and published Anokii package. */
final class AdminWorkflowTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testLoginProtectsTheRenderedAnalyticsAndContactInboxThroughLogout(): void
    {
        $root = dirname(__DIR__, 3);
        $database = sys_get_temp_dir() . '/rhtcircle-admin-' . bin2hex(random_bytes(8)) . '.sqlite';
        $password = bin2hex(random_bytes(24));
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_SKIP_DOTENV=true');
        putenv('WAASEYAA_DB=' . $database);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=' . bin2hex(random_bytes(32)));
        putenv('WAASEYAA_DEV_FALLBACK_ACCOUNT=false');
        putenv('RHTCIRCLE_ADMIN_PASSWORD=' . $password);

        try {
            $this->cli($root, ['install:init']);
            $this->cli($root, ['app:initialize']);
            // A later deployment must still be able to enter the schema coordinator.
            $this->cli($root, ['install:init']);
            $this->cli($root, ['app:create-admin', 'admin@example.invalid', '--name=Synthetic admin']);
            putenv('RHTCIRCLE_ADMIN_PASSWORD');

            $anonymous = $this->request($root, '/admin/anokii/contact');
            self::assertSame(302, $anonymous->getStatusCode());
            self::assertStringStartsWith('/admin/login', (string) $anonymous->headers->get('Location'));

            $form = $this->request($root, '/admin/login?next=/admin/anokii/analytics');
            self::assertSame(200, $form->getStatusCode());
            self::assertSame(1, preg_match('/name="_csrf_token" value="([^"]+)"/', (string) $form->getContent(), $token));
            $data = [
                'email' => 'admin@example.invalid', 'password' => 'wrong-password',
                '_csrf_token' => html_entity_decode($token[1]), 'next' => '/admin/anokii/analytics',
            ];
            self::assertSame(401, $this->request($root, '/admin/login', $data)->getStatusCode());
            $data['password'] = $password;
            $signedIn = $this->request($root, '/admin/login', $data);
            self::assertSame(302, $signedIn->getStatusCode(), (string) $signedIn->getContent());
            self::assertSame('/admin/anokii/analytics', $signedIn->headers->get('Location'));

            $kernel = new HttpKernel($root);
            $kernel->bootForCli();
            $contacts = new ContactRepository($kernel->getDatabase(), 'synthetic-test-secret');
            $contacts->store('Synthetic resident', 'resident@example.invalid', 'other',
                'Synthetic correction <script>not-executed</script>', null, null, true, CampaignRegistry::FACEBOOK);

            $inbox = $this->request($root, '/admin/anokii/contact');
            self::assertSame(200, $inbox->getStatusCode(), (string) $inbox->getContent());
            self::assertStringContainsString('Synthetic correction &lt;script&gt;', (string) $inbox->getContent());
            self::assertStringContainsString('Synthetic admin', (string) $inbox->getContent());

            $analytics = $this->request($root, '/admin/anokii/analytics');
            self::assertSame(200, $analytics->getStatusCode(), (string) $analytics->getContent());
            self::assertStringContainsString('Shared campaigns and contact messages', (string) $analytics->getContent());
            self::assertStringContainsString('Election guide: final-week Facebook post', (string) $analytics->getContent());
            self::assertSame(1, $contacts->count());

            self::assertSame(302, $this->request($root, '/admin/logout')->getStatusCode());
            self::assertSame(302, $this->request($root, '/admin/anokii/contact')->getStatusCode());
            self::assertSame(302, $this->request($root, '/admin/anokii/analytics')->getStatusCode());
        } finally {
            putenv('RHTCIRCLE_ADMIN_PASSWORD');
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            unset($contacts, $kernel);
            gc_collect_cycles();
            foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /** @param array<string, string>|null $post */
    private function request(string $root, string $url, ?array $post = null): Response
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $_GET = $query;
        $_POST = $post ?? [];
        $_COOKIE = [];
        $_SERVER = [
            'REQUEST_METHOD' => $post === null ? 'GET' : 'POST',
            'REQUEST_URI' => $url, 'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $root . '/public/index.php',
            'HTTP_HOST' => 'localhost', 'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '192.0.2.10', 'REQUEST_TIME_FLOAT' => microtime(true),
            'HTTP_ACCEPT' => 'text/html', 'HTTP_USER_AGENT' => 'Synthetic admin integration test',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];

        return (new HttpKernel($root))->handle();
    }

    /** @param list<string> $arguments */
    private function cli(string $root, array $arguments): void
    {
        $process = proc_open([PHP_BINARY, $root . '/vendor/bin/waaseyaa', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output);
    }
}
