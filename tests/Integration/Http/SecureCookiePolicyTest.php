<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\Middleware\CsrfMiddleware;
use Waaseyaa\User\Middleware\SessionMiddleware;

/**
 * Issue #13: rhtcircle.ca is HTTPS-only, so a deployed environment must mark
 * both session cookies Secure.
 *
 * Cloudflare terminates TLS at its edge and forwards plain HTTP through
 * cloudflared to Caddy, so this app never sees a TLS connection. Two
 * independent levers are involved, and each is asserted separately because
 * they fail independently:
 *
 *   PHPSESSID  <- `session.cookie.secure => true`, an explicit switch, applied
 *                 by SessionMiddleware through ini_set('session.cookie_secure').
 *                 It is emitted by PHP's own session handler, NOT through
 *                 Symfony's cookie bag, so it is asserted via ini state.
 *   XSRF-TOKEN <- `Request::isSecure()`, which is only true because
 *                 `trusted_proxies` lets Symfony honour Cloudflare's
 *                 `X-Forwarded-Proto: https`. CsrfMiddleware exposes no secure
 *                 option of its own, so that config is the only lever for it.
 *
 * Why this does not boot a production kernel: `APP_ENV=production` activates the
 * field-access preflight, which by design refuses to boot unless
 * `.waaseyaa/field-access-preflight.json` matches the live schema. A throwaway
 * test database never matches the committed production artifact. Rather than
 * weaken that guard for a test, the chain is asserted link by link: the config
 * contract, then each middleware's cookie output given that config.
 */
final class SecureCookiePolicyTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
    }

    // ------------------------------------------------------------------
    // 1. The config contract
    // ------------------------------------------------------------------

    public function testProductionConfigRequiresSecureCookiesAndTrustsTheProxy(): void
    {
        $config = $this->loadConfig('production');

        self::assertTrue(
            $config['session']['cookie']['secure'],
            'production must require Secure explicitly, not rely on auto-detection',
        );
        self::assertSame(
            ['REMOTE_ADDR'],
            $config['trusted_proxies'],
            'the single connecting peer is trusted, deliberately not a CIDR range',
        );
    }

    public function testStagingIsTreatedAsHttpsOnlyToo(): void
    {
        $config = $this->loadConfig('staging');

        self::assertTrue($config['session']['cookie']['secure']);
        self::assertSame(['REMOTE_ADDR'], $config['trusted_proxies']);
    }

    public function testLocalConfigDoesNotForceSecureOrTrustAnyProxy(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $config = $this->loadConfig($environment);

            self::assertSame(
                'auto',
                $config['session']['cookie']['secure'],
                "$environment must not force Secure: the cookie would be discarded over http://127.0.0.1",
            );
            self::assertSame(
                [],
                $config['trusted_proxies'],
                "$environment must keep Symfony's default of ignoring X-Forwarded-* headers",
            );
        }
    }

    // ------------------------------------------------------------------
    // 2. PHPSESSID, given the production config
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    public function testSessionCookieIsSecureUnderTheProductionConfig(): void
    {
        $this->runSession($this->loadConfig('production')['session']['cookie'], behindHttpsProxy: true);

        self::assertSame('1', ini_get('session.cookie_secure'), 'PHPSESSID must be Secure in production');
        self::assertSame('1', ini_get('session.cookie_httponly'), 'PHPSESSID must stay HttpOnly');
        self::assertSame('Lax', ini_get('session.cookie_samesite'));
    }

    #[RunInSeparateProcess]
    public function testSessionCookieStaysSecureEvenWithoutTheProxyHeader(): void
    {
        // Defence in depth: the explicit switch must not depend on proxy
        // detection, so a missing or altered header cannot silently downgrade it.
        $this->runSession($this->loadConfig('production')['session']['cookie'], behindHttpsProxy: false);

        self::assertSame('1', ini_get('session.cookie_secure'));
    }

    #[RunInSeparateProcess]
    public function testSessionCookieIsNotSecureLocally(): void
    {
        $this->runSession($this->loadConfig('local')['session']['cookie'], behindHttpsProxy: false);

        self::assertSame(
            '0',
            ini_get('session.cookie_secure'),
            'a Secure cookie over http://127.0.0.1 would be discarded, breaking local admin login',
        );
    }

    // ------------------------------------------------------------------
    // 3. XSRF-TOKEN, given the production trusted_proxies
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    public function testXsrfCookieIsSecureBehindTheTrustedProxy(): void
    {
        $cookie = $this->issueXsrfCookie(
            trustedProxies: $this->loadConfig('production')['trusted_proxies'],
            behindHttpsProxy: true,
        );

        self::assertNotNull($cookie, 'an HTML response with an active session must carry XSRF-TOKEN');
        self::assertTrue($cookie->isSecure(), 'XSRF-TOKEN must be Secure in production');
        self::assertFalse($cookie->isHttpOnly(), 'XSRF-TOKEN is read by scripts by design');
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
    }

    #[RunInSeparateProcess]
    public function testXsrfCookieIsNotSecureLocally(): void
    {
        $cookie = $this->issueXsrfCookie(
            trustedProxies: $this->loadConfig('local')['trusted_proxies'],
            behindHttpsProxy: false,
        );

        self::assertNotNull($cookie);
        self::assertFalse($cookie->isSecure());
    }

    #[RunInSeparateProcess]
    public function testXsrfCookieIsNotSecureWhenNoProxyIsTrusted(): void
    {
        // Proves the lever really is `trusted_proxies`: the same forwarded
        // header, with nothing trusted, must NOT be honoured. This is the
        // framework's fail-closed behaviour and must not be weakened.
        $cookie = $this->issueXsrfCookie(trustedProxies: [], behindHttpsProxy: true);

        self::assertNotNull($cookie);
        self::assertFalse($cookie->isSecure(), 'an untrusted X-Forwarded-Proto must never be believed');
    }

    // ------------------------------------------------------------------
    // 4. Public pages stay cookie-free (real kernel, bootable environment)
    // ------------------------------------------------------------------

    public function testAnonymousPublicPagesRemainCookieFree(): void
    {
        $database = sys_get_temp_dir() . '/rhtcircle-securecookie-' . bin2hex(random_bytes(8)) . '.sqlite';
        $restore = [];
        foreach (['APP_ENV', 'APP_DEBUG', 'WAASEYAA_DB', 'WAASEYAA_APP_SECRET', 'WAASEYAA_JWT_SECRET'] as $name) {
            $restore[$name] = getenv($name);
        }

        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=false');
        putenv('WAASEYAA_DB=' . $database);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(random_bytes(32)));
        putenv('WAASEYAA_JWT_SECRET=secure-cookie-policy-test-secret');

        try {
            $this->runCli('db:init');

            foreach (['/', '/news', '/communities/sagamok', '/sitemap.xml'] as $path) {
                $response = $this->kernelRequest($path);

                self::assertSame(200, $response->getStatusCode(), "$path should render");
                self::assertSame(
                    [],
                    $response->headers->getCookies(),
                    "$path must set no cookie: the stateless-path allowlist keeps public pages shared-cache friendly",
                );
            }
        } finally {
            foreach ($restore as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
            foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function loadConfig(string $environment): array
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=' . $environment);

        try {
            /** @var array<string, mixed> $config */
            $config = require $this->projectRoot . '/config/waaseyaa.php';

            return $config;
        } finally {
            putenv($previous === false ? 'APP_ENV' : 'APP_ENV=' . $previous);
        }
    }

    /** @param array<string, mixed> $cookieOptions */
    private function runSession(array $cookieOptions, bool $behindHttpsProxy): void
    {
        $middleware = new SessionMiddleware(
            $this->createMock(EntityRepositoryInterface::class),
            sessionCookieOptions: $cookieOptions,
        );

        $middleware->process($this->request($behindHttpsProxy), new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('ok');
            }
        });
    }

    /** @param list<string> $trustedProxies */
    private function issueXsrfCookie(array $trustedProxies, bool $behindHttpsProxy): ?Cookie
    {
        // Symfony resolves the 'REMOTE_ADDR' sentinel against $_SERVER at
        // setTrustedProxies() time, not per request, so $_SERVER must carry the
        // peer address first. Under php-fpm that is always populated; here it
        // has to be set explicitly or the sentinel is silently dropped and
        // nothing ends up trusted.
        $_SERVER['REMOTE_ADDR'] = '172.18.0.2';

        if ($trustedProxies !== []) {
            Request::setTrustedProxies($trustedProxies, Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        }

        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        // CsrfMiddleware only attaches the cookie when a token already exists.
        CsrfMiddleware::token();

        $response = new Response('<html></html>');
        $response->headers->set('Content-Type', 'text/html');
        CsrfMiddleware::attachCookieIfHtml($this->request($behindHttpsProxy), $response);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'XSRF-TOKEN') {
                return $cookie;
            }
        }

        return null;
    }

    private function request(bool $behindHttpsProxy): Request
    {
        $server = [
            'REMOTE_ADDR' => '172.18.0.2',
            'HTTP_HOST' => 'rhtcircle.ca',
        ];
        if ($behindHttpsProxy) {
            // Exactly what Cloudflare sends, verified against the live edge.
            $server['HTTP_X_FORWARDED_PROTO'] = 'https';
            $server['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
        }

        return Request::create('/admin/login', 'GET', [], [], [], $server);
    }

    private function kernelRequest(string $uri): Response
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
