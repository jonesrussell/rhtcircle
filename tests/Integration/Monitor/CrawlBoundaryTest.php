<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Monitor\CrawlBoundary;
use App\Monitor\ProtectedResourceException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Review finding 1: the crawl boundary must be one authority, and it must hold
 * against paths written to evade it.
 *
 * Previously two lists disagreed. `LinkExtractor::NEVER_FOLLOW` knew about
 * `/members`; `GateDetector::LOGIN_PATH_MARKERS` did not, and the gate is what
 * runs after a fetch. A public URL redirecting to `/members/bulletin` was
 * therefore fetched, hashed, snapshotted and titled.
 *
 * These tests are adversarial by design: every "lookalike" case below is a way
 * of writing a protected path that does not literally read as one.
 */
final class CrawlBoundaryTest extends TestCase
{
    private const ORIGIN = 'https://www.sagamokanishnawbek.test';

    /** Every family named in the specification, plus the auth endpoints. */
    public static function protectedPaths(): iterable
    {
        yield 'members root' => ['/members'];
        yield 'members trailing slash' => ['/members/'];
        yield 'members descendant' => ['/members/bulletin'];
        yield 'members deep descendant' => ['/members/2026/07/minutes'];
        yield 'member-portal' => ['/member-portal'];
        yield 'member-portal descendant' => ['/member-portal/documents'];
        yield 'portal' => ['/portal'];
        yield 'portal descendant' => ['/portal/files/report.pdf'];
        yield 'account' => ['/account'];
        yield 'account descendant' => ['/account/settings'];
        yield 'admin' => ['/admin'];
        yield 'admin descendant' => ['/admin/users'];

        // Written differently, same place.
        yield 'uppercase' => ['/Members'];
        yield 'mixed case descendant' => ['/MeMbErS/News'];
        yield 'percent-encoded first letter' => ['/%6Dembers'];
        yield 'fully percent-encoded' => ['/%6D%65%6D%62%65%72%73'];
        yield 'double-encoded' => ['/%256Dembers'];
        yield 'duplicate slashes' => ['//members'];
        yield 'triple slashes descendant' => ['///members///bulletin'];
        yield 'dot segment' => ['/./members'];
        yield 'parent traversal' => ['/news/../members'];
        yield 'deep traversal' => ['/a/b/c/../../../admin'];
        yield 'encoded traversal' => ['/news/%2E%2E/members'];
        yield 'backslash separator' => ['/\\members'];
        yield 'extension form' => ['/members.php'];
        yield 'encoded slash descendant' => ['/members%2Fbulletin'];
        yield 'trailing dot-slash' => ['/members/./'];

        // Auth endpoints.
        yield 'login' => ['/login'];
        yield 'wp-admin' => ['/wp-admin/edit.php'];
        yield 'sso' => ['/sso/redirect'];
        yield 'user login' => ['/user/login'];
    }

    /**
     * The other half of the claim. Without these, a boundary that returned
     * `true` unconditionally would pass every test above.
     */
    public static function publicPaths(): iterable
    {
        yield 'root' => ['/'];
        yield 'news' => ['/news'];
        yield 'news article' => ['/news/2026/community-meeting'];

        // Prefix lookalikes: these share a textual prefix with a protected
        // family and are ordinary public pages. A `str_starts_with()` boundary
        // would wrongly refuse every one of them, silently blinding the
        // monitor to legitimate content.
        yield 'membership (not members)' => ['/membership'];
        yield 'membership descendant' => ['/membership/how-to-apply'];
        yield 'accounts-payable (not account)' => ['/accounts-payable'];
        yield 'administration (not admin)' => ['/administration-office'];
        yield 'portals-of-history (not portal)' => ['/portals-of-history'];
        yield 'logins-explained (not login)' => ['/logins-explained'];
        yield 'authority (not auth)' => ['/authority'];
        yield 'registry (not register)' => ['/registry'];
        yield 'dashboards-report (not dashboard)' => ['/dashboards-report'];
    }

    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('protectedPaths')]
    public function a_protected_path_is_recognized_however_it_is_written(string $path): void
    {
        self::assertTrue(
            CrawlBoundary::isProtected(self::ORIGIN . $path),
            sprintf('%s must be recognised as protected', $path),
        );
        self::assertTrue(
            CrawlBoundary::isExcludedFromDiscovery(self::ORIGIN . $path),
            'protected implies never queued for discovery',
        );
    }

    #[Test]
    #[DataProvider('publicPaths')]
    public function a_public_path_is_not_mistaken_for_a_protected_one(string $path): void
    {
        self::assertFalse(
            CrawlBoundary::isProtected(self::ORIGIN . $path),
            sprintf('%s is a public page and must remain monitorable', $path),
        );
    }

    #[Test]
    #[DataProvider('protectedPaths')]
    public function assert_public_throws_for_every_protected_form(string $path): void
    {
        $this->expectException(ProtectedResourceException::class);

        CrawlBoundary::assertPublic(self::ORIGIN . $path, 'fetch');
    }

    #[Test]
    public function assert_public_is_silent_for_a_public_url(): void
    {
        CrawlBoundary::assertPublic(self::ORIGIN . '/news/bulletin', 'fetch');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function the_exception_names_the_stage_and_does_not_invite_persisting_the_url(): void
    {
        try {
            CrawlBoundary::assertPublic(self::ORIGIN . '/members/private', 'snapshot');
            self::fail('expected a ProtectedResourceException');
        } catch (ProtectedResourceException $error) {
            self::assertSame('snapshot', $error->stage);
            // The message an operator sees must not carry the portal path.
            self::assertStringNotContainsString('/members/private', $error->getMessage());
            self::assertStringContainsString('snapshot', $error->getMessage());
        }
    }

    #[Test]
    public function query_strings_and_fragments_do_not_change_the_verdict(): void
    {
        // The path is what identifies the resource. A public page that merely
        // mentions a portal path in a query parameter is still public, and a
        // portal page is still protected however it is decorated.
        self::assertFalse(CrawlBoundary::isProtected(self::ORIGIN . '/news?return=/members'));
        self::assertFalse(CrawlBoundary::isProtected(self::ORIGIN . '/news#members'));
        self::assertTrue(CrawlBoundary::isProtected(self::ORIGIN . '/members?page=2'));
        self::assertTrue(CrawlBoundary::isProtected(self::ORIGIN . '/members#top'));
    }

    #[Test]
    public function normalization_terminates_on_hostile_input(): void
    {
        // Deeply nested encoding must not hang the collector. The bound is
        // what makes over-matching safe rather than unbounded.
        $nested = self::ORIGIN . '/' . str_repeat('%25', 40) . '6Dembers';

        $start = hrtime(true);
        CrawlBoundary::isProtected($nested);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(100, $elapsedMs, 'normalization must be bounded');
    }

    #[Test]
    public function discovery_also_declines_transactional_endpoints(): void
    {
        // Not a retention boundary — these are simply not public content and
        // can have side effects when requested.
        self::assertTrue(CrawlBoundary::isExcludedFromDiscovery(self::ORIGIN . '/cart'));
        self::assertTrue(CrawlBoundary::isExcludedFromDiscovery(self::ORIGIN . '/checkout/step-2'));

        // ...but they are not "protected", because calling them members-only
        // would misreport what they are in events and on the dashboard.
        self::assertFalse(CrawlBoundary::isProtected(self::ORIGIN . '/cart'));
    }
}
