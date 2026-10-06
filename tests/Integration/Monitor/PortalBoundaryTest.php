<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Entity\PortalAccessState;
use App\Entity\PortalUpdateNote;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\SagamokMonitorRepository;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Attribute\EntityMetadataReader;

/**
 * Spec §9.6: the members portal is properly access-controlled, so there is
 * nothing here to monitor and nothing here that could be pointed at it.
 *
 * Two layers of assertion, because a policy nobody can violate beats a policy
 * everybody must remember:
 *
 *  - **Shape:** the two portal record types declare no URL, attachment, body,
 *    hash or identifier field, read from the definitions so a later field
 *    addition fails here rather than shipping.
 *  - **Source:** grep assertions over the whole monitor tree reject the
 *    machinery a portal collector would need — auth headers, credentials,
 *    cookie jars, archive clients and archive hosts. A future collector cannot
 *    be added quietly.
 */
final class PortalBoundaryTest extends TestCase
{
    private string $monitorSourceDir;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->monitorSourceDir = $this->projectRoot . '/src/Monitor';
    }

    // ------------------------------------------------------------------
    // Shape: editorial records only
    // ------------------------------------------------------------------

    /**
     * Field-name fragments that would turn an editorial note into a carrier for
     * portal material, a locator, or a member identifier.
     *
     * @return list<string>
     */
    private static function forbiddenFieldFragments(): array
    {
        return [
            'url', 'uri', 'link', 'href', 'endpoint', 'host', 'domain',
            'attachment', 'file', 'path', 'blob', 'body', 'content', 'html', 'document',
            'hash', 'checksum', 'digest', 'fingerprint', 'signature',
            'item_key', 'external_id', 'member_id', 'user_id', 'account',
            'email', 'phone', 'password', 'token', 'credential',
        ];
    }

    public function testPortalRecordTypesDeclareNoLocatorBodyHashOrIdentifierField(): void
    {
        foreach ([PortalAccessState::class, PortalUpdateNote::class] as $class) {
            $fields = array_keys(EntityMetadataReader::resolveFields($class));
            self::assertNotEmpty($fields, 'the type must declare fields at all');

            foreach ($fields as $field) {
                foreach (self::forbiddenFieldFragments() as $fragment) {
                    self::assertStringNotContainsString(
                        $fragment,
                        strtolower($field),
                        sprintf('%s declares "%s", which could carry portal material or identify a member', $class, $field),
                    );
                }
            }
        }
    }

    public function testPortalRecordsExposeOnlyEditorialStatementFields(): void
    {
        // Asserted at the EMISSION point — the keys `view()` actually returns —
        // not at the `PROJECTION` constant.
        //
        // Those two disagree, and did so while this test passed. `view()`
        // mutates its result after copying the constant: it adds `stalled` for
        // sources, and for portal state it adds `last_verified_on` and unsets
        // `verified_on` (month precision only, spec §7.1). So the old assertion
        // claimed readers receive `['state', 'verified_on', 'statement']` when
        // they actually receive `['state', 'statement', 'last_verified_on']`.
        //
        // The closed-set guarantee has to be pinned where values leave the
        // repository. Against the constant, adding `$out['method_note'] = …`
        // inside `view()` keeps every assertion green while the value reaches
        // every template. That emission-point assertion lives in
        // MonitorCompositionTest, which has a booted kernel; this file keeps
        // the constant check as a secondary, failure-localising signal.
        self::assertSame(
            ['state', 'verified_on', 'statement'],
            SagamokMonitorRepository::projectedKeys(MonitorEntityTypes::PORTAL_ACCESS_STATE),
        );
        self::assertSame(
            ['title_supplied', 'supplied_on', 'official_date', 'summary'],
            SagamokMonitorRepository::projectedKeys(MonitorEntityTypes::PORTAL_UPDATE_NOTE),
        );
    }

    public function testInternalReviewFieldsAreNeverProjected(): void
    {
        foreach ([MonitorEntityTypes::PORTAL_ACCESS_STATE, MonitorEntityTypes::PORTAL_UPDATE_NOTE] as $type) {
            $keys = SagamokMonitorRepository::projectedKeys($type);
            self::assertNotContains('method_note', $keys);
            self::assertNotContains('review_note', $keys);
            self::assertNotContains('verified_by_role', $keys);
            self::assertNotContains('supplied_by_role', $keys);
        }
    }

    // ------------------------------------------------------------------
    // Source: the machinery a portal collector would need does not exist
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}> label => [pattern, why]
     */
    public static function forbiddenSourcePatterns(): iterable
    {
        yield 'authorization header' => ['/Authorization\s*[:=]|[\'"]Authorization[\'"]/i', 'an auth header is how protected material would be requested'];
        yield 'bearer token' => ['/[\'"]Bearer\s|bearer_token/i', 'same'];
        yield 'password' => ['/\bpassword\b\s*[:=]|\$password\b|[\'"]password[\'"]\s*=>/i', 'a credential input has no place in a public-site collector'];
        yield 'credentials' => ['/\bcredentials?\b\s*[:=]|CURLOPT_USERPWD|setBasicAuth/i', 'same'];
        yield 'cookie jar' => ['/CURLOPT_COOKIE|cookie_?jar|CookieJar|setCookie\(/i', 'a session would be needed to hold a login'];
        yield 'archive client' => ['/web\.archive\.org|archive\.ph|archive\.today|timetravel\.mementoweb|wayback/i', 'archive queries are out of scope by decision'];
        yield 'search index client' => ['/googleapis\.com\/customsearch|bing\.com\/v7|serpapi/i', 'search-index queries are out of scope by decision'];
        yield 'login form post' => ['/\/login[\'"]?\s*,\s*\[|login_?form|doLogin|authenticate\(/i', 'no authentication path may exist'];
    }

    /**
     * @param string $pattern
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenSourcePatterns')]
    public function testTheMonitorSourceTreeContainsNoPortalCollectorMachinery(string $pattern, string $why): void
    {
        $offenders = [];
        foreach ($this->monitorSourceFiles() as $file) {
            $contents = (string) file_get_contents($file);
            // Strip comments before matching: the boundary is enforced on code,
            // and the docblocks in this tree deliberately DISCUSS the things
            // being forbidden, which a naive grep would flag.
            $code = $this->stripComments($contents);
            if (preg_match($pattern, $code) === 1) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, $why);
    }

    public function testTheFetcherContractExposesNoCredentialSurface(): void
    {
        // The structural guarantee: a portal collector cannot be written
        // against this interface without changing the interface itself.
        $reflection = new \ReflectionMethod(\App\Monitor\PageFetcherInterface::class, 'fetch');

        self::assertCount(1, $reflection->getParameters(), 'fetch() takes a URL and nothing else');
        self::assertSame('url', $reflection->getParameters()[0]->getName());
        self::assertSame('string', (string) $reflection->getParameters()[0]->getType());
    }

    public function testNoMonitorSourceFileReferencesAPortalHost(): void
    {
        foreach ($this->monitorSourceFiles() as $file) {
            $code = $this->stripComments((string) file_get_contents($file));
            self::assertDoesNotMatchRegularExpression(
                '/members?\.[a-z0-9-]+\.(com|ca|org)/i',
                $code,
                basename($file) . ' must not name a members-portal host',
            );
        }
    }

    public function testNoScheduledTaskOrCommandTargetsThePortal(): void
    {
        $files = array_merge(
            $this->monitorSourceFiles(),
            glob($this->projectRoot . '/src/Schedule/*.php') ?: [],
            glob($this->projectRoot . '/src/Command/*.php') ?: [],
        );

        foreach ($files as $file) {
            $code = $this->stripComments((string) file_get_contents($file));
            self::assertDoesNotMatchRegularExpression(
                '/portal[_-]?(?:collect|fetch|crawl|scrape|sync|harvest|login|auth)/i',
                $code,
                basename($file) . ' must not declare a portal collection path',
            );
        }
    }

    /** @return list<string> */
    private function monitorSourceFiles(): array
    {
        $files = glob($this->monitorSourceDir . '/*.php') ?: [];
        self::assertNotEmpty($files, 'the monitor source tree must exist for this assertion to mean anything');

        return $files;
    }

    /**
     * Remove comments and docblocks, so the grep assertions run against code.
     */
    private function stripComments(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
