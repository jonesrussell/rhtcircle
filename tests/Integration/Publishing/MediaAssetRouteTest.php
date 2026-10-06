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

        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_MEDIA_UPLOADS_DIR', 'WAASEYAA_FILES_ROOT'] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        mkdir($this->uploadsDir, 0700, true);
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $this->databasePath);
        putenv('WAASEYAA_MEDIA_UPLOADS_DIR=' . $this->uploadsDir);
        putenv('WAASEYAA_FILES_ROOT=' . $this->uploadsDir);

        $this->runCli('db:init');
        $this->runCli('install:init');
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

        // Bytes alone are not public assets. A published catalogue row grants
        // anonymous access, and retracting it must withdraw the same URL.
        self::assertSame(404, $this->request('/media/uploads/' . $name)->getStatusCode());
        $kernel = new HttpKernel($this->projectRoot);
        $kernel->bootForCli();
        $repository = $kernel->getEntityTypeManager()->getRepository('media');
        $media = $repository->create([
            'bundle' => 'image', 'name' => 'Test image',
            'source_uri' => 'public://' . $name, 'status' => true,
        ]);
        $repository->save($media, validate: false);
        $mediaId = $media->id();

        $response = $this->request('/media/uploads/' . $name);
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        $kernel = new HttpKernel($this->projectRoot);
        $kernel->bootForCli();
        $repository = $kernel->getEntityTypeManager()->getRepository('media');
        $media = $repository->find($mediaId);
        self::assertNotNull($media);
        $media->set('status', false);
        $repository->save($media, validate: false);
        self::assertSame(404, $this->request('/media/uploads/' . $name)->getStatusCode());

        self::assertSame(404, $this->request('/media/uploads/not-a-content-hash.png')->getStatusCode());
    }

    public function testPublisherToolsCreateAndRetractAnArticleWithoutAdministratorAccess(): void
    {
        $kernel = new HttpKernel($this->projectRoot);
        $kernel->bootForCli();
        $tools = $kernel->buildHandlerContainer()->get(\Waaseyaa\AI\Tools\ToolRegistryInterface::class);
        $actor = new \App\Publishing\ArticlePublisherAccount();
        self::assertFalse($actor->hasPermission(\Waaseyaa\Node\NodePermissions::ADMINISTER));
        self::assertFalse($actor->hasPermission(\Waaseyaa\Media\MediaPermissions::ADMINISTER));
        self::assertFalse($actor->hasPermission(\Waaseyaa\Node\NodePermissions::create('page')));
        self::assertFalse($actor->hasPermission(\Waaseyaa\Media\MediaPermissions::create('document')));
        $values = [
            'slug' => 'synthetic-publishing-check', 'title' => 'Synthetic publishing check',
            'community_slug' => 'circle', 'summary' => 'Fixture only.', 'author' => 'Test fixture',
            'date_display' => 'October 5, 2026', 'date_iso' => '2026-10-05',
            'section' => 'RHT Circle analysis', 'body_html' => '<p>Fixture body.</p>',
            'sources_html' => '<p>Fixture source.</p>',
        ];
        $draft = $tools->get('article.createDraft')->impl->execute([
            'values' => $values, 'idempotency_key' => 'fixture-create-article',
        ], $actor);
        self::assertFalse($draft->isError, json_encode($draft->content));
        $row = $draft->structuredContent;
        self::assertFalse($row['status']);
        $entity = $kernel->getEntityTypeManager()->getRepository('node')->find($row['id']);
        self::assertNotNull($entity);
        self::assertFalse($kernel->getAccessHandler()->check($entity, 'view', new \Waaseyaa\User\AnonymousUser())->isAllowed());
        $denied = $tools->get('article.createDraft')->impl->execute([
            'values' => $values, 'idempotency_key' => 'fixture-anonymous-denied',
        ], new \Waaseyaa\User\AnonymousUser());
        self::assertTrue($denied->isError);
        foreach (['publish' => true, 'unpublish' => false] as $operation => $status) {
            $result = $tools->get('article.' . $operation)->impl->execute([
                'id' => (string) $row['id'], 'expected_revision_id' => $row['revision_id'],
                'idempotency_key' => 'fixture-' . $operation . '-article',
            ], $actor);
            self::assertFalse($result->isError, json_encode($result->content));
            $row = $result->structuredContent;
            self::assertSame($status, $row['status']);
        }
        $asset = $tools->get('asset.upload')->impl->execute([
            'filename' => 'fixture.png',
            'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nHgAAAAASUVORK5CYII=',
        ], $actor);
        self::assertFalse($asset->isError, json_encode($asset->content));
        self::assertSame('image/png', $asset->structuredContent['mime']);
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
