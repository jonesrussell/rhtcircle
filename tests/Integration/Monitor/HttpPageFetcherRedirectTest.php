<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Monitor\HttpPageFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Review finding 2: the fetcher must never *request* an off-origin URL.
 *
 * `follow_location` was enabled, so the transport fetched the redirect target
 * and the origin check ran afterwards — meaning an off-origin host received a
 * request from us and was only then rejected. That is precisely the promise
 * this feature makes to the Nation whose site it watches, so it is asserted
 * against a **real HTTP server** rather than a mock.
 *
 * Two local servers run on loopback: an "origin" the fetcher is bound to, and a
 * separate "elsewhere" that records every request it receives. The assertion
 * that matters is that `elsewhere` records **zero**. No production Sagamok
 * request occurs anywhere in this file.
 */
final class HttpPageFetcherRedirectTest extends TestCase
{
    /** @var resource|null */
    private $originServer = null;
    /** @var resource|null */
    private $elsewhereServer = null;
    private int $originPort = 0;
    private int $elsewherePort = 0;
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rhtcircle-redirect-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/origin', 0o755, true);
        mkdir($this->tmp . '/elsewhere', 0o755, true);

        $this->originPort = $this->reservePort();
        $this->elsewherePort = $this->reservePort();

        // The off-origin server appends to a hit log on every request. If the
        // fetcher ever touches it, the file exists and the test fails.
        file_put_contents($this->tmp . '/elsewhere/router.php', <<<'PHP'
            <?php
            file_put_contents(getenv('HIT_LOG'), $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
            header('Content-Type: text/html');
            echo '<html><body>off-origin content</body></html>';
            PHP);

        $elsewhere = 'http://127.0.0.1:' . $this->elsewherePort;
        file_put_contents($this->tmp . '/origin/router.php', <<<PHP
            <?php
            \$path = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);
            switch (\$path) {
                case '/relative-redirect':
                    header('Location: /landing', true, 302);
                    return;
                case '/dotted-redirect':
                    header('Location: landing', true, 302);
                    return;
                case '/offsite-redirect':
                    header('Location: {$elsewhere}/secret', true, 302);
                    return;
                case '/loop-a':
                    header('Location: /loop-b', true, 302);
                    return;
                case '/loop-b':
                    header('Location: /loop-a', true, 302);
                    return;
                case '/self-loop':
                    header('Location: /self-loop', true, 302);
                    return;
                case '/landing':
                    echo '<html><head><title>Landing</title></head><body>arrived</body></html>';
                    return;
                default:
                    http_response_code(404);
                    echo 'not found';
            }
            PHP);

        $this->originServer = $this->startServer($this->tmp . '/origin', $this->originPort);
        $this->elsewhereServer = $this->startServer($this->tmp . '/elsewhere', $this->elsewherePort);
    }

    protected function tearDown(): void
    {
        foreach ([$this->originServer, $this->elsewhereServer] as $server) {
            if (is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }
        }
        $this->originServer = null;
        $this->elsewhereServer = null;

        if (is_dir($this->tmp)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isFile() || $item->isLink() ? unlink($item->getPathname()) : rmdir($item->getPathname());
            }
            rmdir($this->tmp);
        }
    }

    private function origin(): string
    {
        return 'http://127.0.0.1:' . $this->originPort;
    }

    private function fetcher(): HttpPageFetcher
    {
        return new HttpPageFetcher($this->origin() . '/');
    }

    /** Requests the off-origin server actually received. */
    private function offOriginHits(): int
    {
        $log = $this->tmp . '/hits.log';

        return is_file($log) ? count(array_filter(explode("\n", (string) file_get_contents($log)))) : 0;
    }

    // ------------------------------------------------------------------

    public function testASameOriginRelativeRedirectIsFollowed(): void
    {
        $result = $this->fetcher()->fetch($this->origin() . '/relative-redirect');

        self::assertTrue($result->ok, $result->error ?? '');
        self::assertSame(200, $result->statusCode);
        self::assertStringContainsString('arrived', $result->body);
        self::assertSame($this->origin() . '/landing', $result->finalUrl);
    }

    public function testAPathRelativeRedirectResolvesAgainstTheBaseDirectory(): void
    {
        // `Location: landing` with no leading slash. Resolved wrongly this
        // either misses the redirect or produces a URL on another host.
        $result = $this->fetcher()->fetch($this->origin() . '/dotted-redirect');

        self::assertTrue($result->ok, $result->error ?? '');
        self::assertStringContainsString('arrived', $result->body);
    }

    public function testAnOffOriginRedirectTargetReceivesZeroRequests(): void
    {
        // The load-bearing assertion of this file.
        $result = $this->fetcher()->fetch($this->origin() . '/offsite-redirect');

        self::assertFalse($result->ok);
        self::assertStringContainsString('origin', (string) $result->error);
        self::assertSame('', $result->body, 'no off-origin body may be retained');

        self::assertSame(
            0,
            $this->offOriginHits(),
            'the off-origin host must never be requested, not merely rejected after the fact',
        );
    }

    public function testTheOffOriginServerIsReachableWhenWeChooseToReachIt(): void
    {
        // Control for the assertion above: prove the hit log works, so a zero
        // there means "never requested" rather than "logging is broken".
        file_get_contents('http://127.0.0.1:' . $this->elsewherePort . '/probe');

        self::assertSame(1, $this->offOriginHits());
    }

    public function testARedirectLoopFailsClosed(): void
    {
        $result = $this->fetcher()->fetch($this->origin() . '/loop-a');

        self::assertFalse($result->ok);
        self::assertStringContainsString('redirect', (string) $result->error);
    }

    public function testASelfReferentialRedirectFailsClosed(): void
    {
        $result = $this->fetcher()->fetch($this->origin() . '/self-loop');

        self::assertFalse($result->ok);
        self::assertStringContainsString('loop', (string) $result->error);
    }

    public function testAnOffOriginStartingUrlIsRefusedBeforeAnyRequest(): void
    {
        $result = $this->fetcher()->fetch('http://127.0.0.1:' . $this->elsewherePort . '/direct');

        self::assertFalse($result->ok);
        self::assertSame(0, $this->offOriginHits());
    }

    // ------------------------------------------------------------------

    private function reservePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        self::assertIsResource($socket, "port reservation failed: {$code} {$message}");
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** @return resource */
    private function startServer(string $docroot, int $port)
    {
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot, $docroot . '/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $docroot,
            ['HIT_LOG' => $this->tmp . '/hits.log', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        self::assertIsResource($server);

        $deadline = microtime(true) + 10;
        do {
            usleep(50_000);
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($probe)) {
                fclose($probe);

                return $server;
            }
        } while (microtime(true) < $deadline);

        self::fail('local test server on port ' . $port . ' did not start');
    }
}
