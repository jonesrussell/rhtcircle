<?php

declare(strict_types=1);

namespace App\Tests\Unit\SagamokMonitor;

use App\Monitor\ContentNormalizer;
use App\Monitor\FetchResult;
use App\Monitor\ExclusionKind;
use App\Monitor\GateDetector;
use PHPUnit\Framework\TestCase;

/**
 * Spec §9.4: update detection and the re-gating gate, at the unit level.
 *
 * The normalizer and the gate are pure, so they are tested directly here; the
 * end-to-end event sequencing is covered against a real kernel in
 * `tests/Integration/Monitor/CollectorRunTest.php`.
 */
final class ChangeDetectionTest extends TestCase
{
    private const PAGE = <<<'HTML'
        <html><head><title>Water advisory</title></head>
        <body><h1>Water advisory</h1><p>The advisory remains in effect.</p></body></html>
        HTML;

    // ------------------------------------------------------------------
    // Normalization: volatile noise must not register as a change
    // ------------------------------------------------------------------

    public function testIdenticalContentHashesEqual(): void
    {
        self::assertSame(ContentNormalizer::hash(self::PAGE), ContentNormalizer::hash(self::PAGE));
    }

    public function testRotatingAssetHashesDoNotRegisterAsAChange(): void
    {
        $a = '<html><head><link href="/css/site.a1b2c3d4e5f6.css"></head><body>Same words</body></html>';
        $b = '<html><head><link href="/css/site.9f8e7d6c5b4a.css"></head><body>Same words</body></html>';

        self::assertSame(ContentNormalizer::hash($a), ContentNormalizer::hash($b));
    }

    public function testCsrfTokensDoNotRegisterAsAChange(): void
    {
        $a = '<body><form><input type="hidden" name="csrf_token" value="aaaaaaaa"></form>Body</body>';
        $b = '<body><form><input type="hidden" name="csrf_token" value="bbbbbbbb"></form>Body</body>';

        self::assertSame(ContentNormalizer::hash($a), ContentNormalizer::hash($b));
    }

    public function testFooterTimestampsDoNotRegisterAsAChange(): void
    {
        $a = '<body>Notice text<footer>Page generated on 2026-07-31 08:00:00</footer></body>';
        $b = '<body>Notice text<footer>Page generated on 2026-08-01 09:30:00</footer></body>';

        self::assertSame(ContentNormalizer::hash($a), ContentNormalizer::hash($b));
    }

    public function testSessionIdsDoNotRegisterAsAChange(): void
    {
        $a = '<body><a href="/x?PHPSESSID=abc123def456">Link</a>Text</body>';
        $b = '<body><a href="/x?PHPSESSID=zzz999yyy888">Link</a>Text</body>';

        self::assertSame(ContentNormalizer::hash($a), ContentNormalizer::hash($b));
    }

    public function testCacheBustersDoNotRegisterAsAChange(): void
    {
        $a = '<body><img src="/logo.png?v=1690000000">Text</body>';
        $b = '<body><img src="/logo.png?v=1699999999">Text</body>';

        self::assertSame(ContentNormalizer::hash($a), ContentNormalizer::hash($b));
    }

    public function testARealContentChangeIsStillDetected(): void
    {
        // The normalizer must not be so aggressive that it flattens the signal
        // it exists to protect. This is the counterweight to every test above.
        $lifted = str_replace('remains in effect', 'has been lifted', self::PAGE);

        self::assertNotSame(ContentNormalizer::hash(self::PAGE), ContentNormalizer::hash($lifted));
    }

    public function testIndentationChangesAreNotAChange(): void
    {
        // Whitespace that already exists is collapsed, so re-indenting a
        // template does not register.
        $reindented = str_replace("\n", "\n\n        ", self::PAGE);

        self::assertSame(ContentNormalizer::hash(self::PAGE), ContentNormalizer::hash($reindented));
    }

    public function testInsertingWhitespaceBetweenAdjacentTagsIsAChange(): void
    {
        // Deliberately pinned as a CHANGE rather than assumed away: collapsing
        // `><` and `> <` together would also merge "<span>a</span><span>b</span>"
        // (reads "ab") with its spaced form (reads "a b"), which are different
        // text. The normalizer removes whitespace VARIATION, not the presence
        // or absence of a separator.
        $spaced = str_replace('><', '> <', self::PAGE);

        self::assertNotSame(ContentNormalizer::hash(self::PAGE), ContentNormalizer::hash($spaced));
    }

    // ------------------------------------------------------------------
    // Snapshot limits
    // ------------------------------------------------------------------

    public function testSnapshotIsCappedAtTwoMegabytes(): void
    {
        $huge = '<body>' . str_repeat('a', 3 * 1024 * 1024) . '</body>';

        self::assertSame(ContentNormalizer::MAX_SNAPSHOT_BYTES, strlen(ContentNormalizer::snapshot($huge)));
        self::assertSame(2 * 1024 * 1024, ContentNormalizer::MAX_SNAPSHOT_BYTES);
    }

    public function testSnapshotIsTakenFromTheNormalizedText(): void
    {
        // A snapshot must be consistent with the hash stored beside it,
        // otherwise an operator comparing them is comparing two different
        // things and will reach a wrong conclusion about what changed.
        $snapshot = ContentNormalizer::snapshot(self::PAGE);

        self::assertSame(ContentNormalizer::normalize(self::PAGE), $snapshot);

        // Normalization strips comments, scripts and styles and collapses
        // whitespace; it does NOT strip markup, so the snapshot stays a
        // readable rendering of what was served.
        $withNoise = str_replace('<body>', "<!-- build 42 --><script>x()</script><body>", self::PAGE);
        $noisySnapshot = ContentNormalizer::snapshot($withNoise);

        self::assertStringNotContainsString('build 42', $noisySnapshot);
        self::assertStringNotContainsString('x()', $noisySnapshot);
        self::assertStringContainsString('Water advisory', $noisySnapshot);
    }

    // ------------------------------------------------------------------
    // Re-gating gate — spec §4.3
    // ------------------------------------------------------------------

    public function testUnauthorizedAndForbiddenAreGated(): void
    {
        self::assertSame('http_401', GateDetector::reason(401, 'https://x/y', ''));
        self::assertSame('http_403', GateDetector::reason(403, 'https://x/y', ''));
    }

    public function testARedirectToALoginPathIsGated(): void
    {
        self::assertSame(
            'redirected_to_login',
            GateDetector::reason(200, 'https://www.sagamokanishnawbek.com/login?next=/notices', self::PAGE),
        );
    }

    public function testALoginShellServedAtTwoHundredIsGated(): void
    {
        // The case that motivates this gate existing at all: the portal's
        // original defect was a 200 with a client-side login overlay. Status
        // code alone calls this page public.
        //
        // The URL here is a PUBLIC path on purpose. This test previously used
        // `https://x/members`, which meant it passed on the body check only
        // because `/members` was missing from the gate's path list — the very
        // gap that let portal content through (review finding 1). With one
        // boundary authority that URL is now caught by path, so exercising
        // body detection requires a path the boundary does not already refuse.
        $shell = '<html><body><h1>Members only</h1>'
            . '<form><input type="password" name="password"></form></body></html>';

        self::assertSame('login_shell', GateDetector::reason(200, 'https://x/news/bulletin', $shell));
    }

    public function testAPortalPathIsGatedRegardlessOfWhatTheBodyLooksLike(): void
    {
        // The scenario the old fixture accidentally concealed: a members-area
        // page that is NOT a login shell. No password input, no login marker,
        // and comfortably over the login-shell size ceiling — so every body
        // heuristic declines. Before the single boundary authority this
        // returned null and the collector hashed it, snapshotted up to 2 MB of
        // it, and took the item title from its <title>.
        $portalPage = '<html><head><title>Member bulletin — March</title></head><body>'
            . str_repeat('<p>Members-only council correspondence.</p>', 3_000)
            . '</body></html>';

        self::assertGreaterThan(60_000, strlen($portalPage), 'must exceed the login-shell size ceiling');
        self::assertStringNotContainsString('password', $portalPage, 'must carry no login marker');

        self::assertSame(
            'redirected_to_login',
            GateDetector::reason(200, 'https://x/members/bulletin', $portalPage),
        );
        self::assertSame(
            ExclusionKind::AuthRequired,
            GateDetector::classify(200, 'https://x/members/bulletin', $portalPage),
        );
    }

    public function testAPublicPageThatMerelyResemblesAPortalPathIsNotGated(): void
    {
        // Mutation control for the two tests above. A boundary that refused
        // anything containing "member" would satisfy them both while silently
        // blinding the monitor to a legitimate public page.
        $page = '<html><head><title>How to apply for membership</title></head><body>'
            . '<p>Membership applications open in April.</p></body></html>';

        self::assertNull(GateDetector::reason(200, 'https://x/membership/how-to-apply', $page));
    }

    public function testANoindexTwoHundredIsExcludedButNotTreatedAsAuthRequired(): void
    {
        // `noindex` means "do not collect or retain this page". It does NOT
        // mean authentication is required — such a page is usually still
        // publicly reachable. Reporting it as a sign-in wall would tell members
        // the Nation restricted access when it did not, which on an
        // accountability dashboard is the most damaging output possible.
        $noindex = '<html><head><meta name="robots" content="noindex,nofollow"></head><body>Text</body></html>';

        self::assertSame('noindex', GateDetector::reason(200, 'https://x/y', $noindex));
        self::assertSame(ExclusionKind::NotForRetention, GateDetector::classify(200, 'https://x/y', $noindex));

        $viaHeader = GateDetector::classify(200, 'https://x/y', '<body>Text</body>', ['x-robots-tag' => 'noindex']);
        self::assertSame(ExclusionKind::NotForRetention, $viaHeader);
    }

    public function testAuthSignalsClassifyAsAuthRequired(): void
    {
        $shell = '<html><body><h1>Members only</h1><form><input type="password"></form></body></html>';

        self::assertSame(ExclusionKind::AuthRequired, GateDetector::classify(401, 'https://x/y', ''));
        self::assertSame(ExclusionKind::AuthRequired, GateDetector::classify(403, 'https://x/y', ''));
        self::assertSame(ExclusionKind::AuthRequired, GateDetector::classify(200, 'https://x/login', self::PAGE));
        self::assertSame(ExclusionKind::AuthRequired, GateDetector::classify(200, 'https://x/members', $shell));
    }

    public function testTheTwoKindsAreDistinctInEveryOutwardFacingWay(): void
    {
        // Event type, health effect and public wording must all differ, so the
        // distinction cannot quietly collapse in one of the three places.
        self::assertNotSame(ExclusionKind::AuthRequired->eventType(), ExclusionKind::NotForRetention->eventType());
        self::assertSame('became_gated', ExclusionKind::AuthRequired->eventType());
        self::assertSame('became_noindex', ExclusionKind::NotForRetention->eventType());

        self::assertTrue(ExclusionKind::AuthRequired->affectsSourceHealth());
        self::assertFalse(
            ExclusionKind::NotForRetention->affectsSourceHealth(),
            'the site is reachable and behaving normally; honouring noindex is not a monitoring fault',
        );

        self::assertNotSame(ExclusionKind::AuthRequired->publicLabel(), ExclusionKind::NotForRetention->publicLabel());
        self::assertStringNotContainsStringIgnoringCase('sign-in', ExclusionKind::NotForRetention->publicLabel());
        self::assertStringNotContainsStringIgnoringCase('login', ExclusionKind::NotForRetention->publicLabel());
    }

    public function testAGatedPageThatAlsoCarriesNoindexIsReportedAsAuthRequired(): void
    {
        // Ordering matters: an access change is the more serious finding, and
        // reporting it as a mere retention preference would understate it.
        $both = '<html><head><meta name="robots" content="noindex"></head>'
            . '<body><h1>Members only</h1><input type="password"></body></html>';

        self::assertSame(ExclusionKind::AuthRequired, GateDetector::classify(200, 'https://x/y', $both));
    }

    public function testAGenuinelyPublicPageIsNotGated(): void
    {
        self::assertNull(GateDetector::reason(200, 'https://www.sagamokanishnawbek.com/notices/water', self::PAGE));
        self::assertNull(GateDetector::classify(200, 'https://www.sagamokanishnawbek.com/notices/water', self::PAGE));
        self::assertFalse(GateDetector::isExcluded(200, 'https://www.sagamokanishnawbek.com/notices/water', self::PAGE));
    }

    public function testALongPublicArticleMentioningLoginIsNotGated(): void
    {
        // False positives here silently stop monitoring a real public page and
        // report it as gated, which is a wrong finding published to members.
        $article = '<html><body>' . str_repeat('Council meeting minutes and discussion. ', 3000)
            . 'Members can also log in to the portal for more.</body></html>';

        self::assertNull(GateDetector::reason(200, 'https://x/minutes', $article));
    }

    // ------------------------------------------------------------------
    // A fetch failure is not a content signal
    // ------------------------------------------------------------------

    public function testAFailedFetchCarriesNoBody(): void
    {
        $failure = FetchResult::failure('https://x/y', 'connection timed out');

        self::assertFalse($failure->ok);
        self::assertSame('', $failure->body);
        self::assertSame(0, $failure->statusCode);
        self::assertSame('connection timed out', $failure->error);
    }
}
