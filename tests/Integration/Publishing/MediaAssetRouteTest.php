<?php

declare(strict_types=1);

namespace App\Tests\Integration\Publishing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Waaseyaa\Foundation\Kernel\HttpKernel;

final class MediaAssetRouteTest extends TestCase
{
    private string $projectRoot;
    private string $databasePath;
    private string $uploadsDir;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $suffix = bin2hex(random_bytes(8));
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-media-' . $suffix . '.sqlite';
        $this->uploadsDir = sys_get_temp_dir() . '/rhtcircle-media-' . $suffix;

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_MEDIA_UPLOADS_DIR'] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        mkdir($this->uploadsDir, 0700, true);
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_MEDIA_UPLOADS_DIR=' . $this->uploadsDir);

        $this->runCli('db:init');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }

        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
        foreach (glob($this->uploadsDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->uploadsDir)) {
            rmdir($this->uploadsDir);
        }
    }

    public function testContentAddressedAssetIsServedAndInvalidNameIsRejected(): void
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nHgAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($bytes);
        $name = hash('sha256', $bytes) . '.png';
        file_put_contents($this->uploadsDir . '/' . $name, $bytes);

        $response = $this->request('/media/uploads/' . $name);
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=31536000', $cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);

        self::assertSame(404, $this->request('/media/uploads/not-a-content-hash.png')->getStatusCode());
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
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
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
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
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
