<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Monitor\CrawlBoundary;
use App\Monitor\HttpPageFetcher;
use App\Monitor\HttpTransportInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The redirect boundary, proven **at the transport seam**.
 *
 * Every other test of this boundary asserts on a return value, which shows what
 * the fetcher *decided*. These assert on the transport's request log, which
 * shows what it actually *did* — the only evidence that answers "did a request
 * reach a members-only URL". The distinction matters because the failure being
 * prevented is not a wrong answer, it is an outbound request to a protected
 * page, which has already happened by the time any return value exists.
 *
 * The recording lives entirely in {@see RecordingHttpTransport}. Production
 * uses `StreamHttpTransport` and records nothing.
 */
final class RedirectBoundaryTransportTest extends TestCase
{
    private const ORIGIN = 'https://www.sagamokanishnawbek.test/';
    private const PUBLIC_START = 'https://www.sagamokanishnawbek.test/news/bulletin';
    private const PROTECTED = 'https://www.sagamokanishnawbek.test/members/bulletin';

    // ------------------------------------------------------------------

    #[Test]
    public function aPublicUrlRedirectingToAProtectedPathNeverRequestsIt(): void
    {
        // The exact reachable scenario from review finding 1.
        $transport = new RecordingHttpTransport()
            ->redirect(self::PUBLIC_START, self::PROTECTED)
            ->page(self::PROTECTED, '<html><body>members-only bulletin</body></html>');

        $result = new HttpPageFetcher(self::ORIGIN, $transport)->fetch(self::PUBLIC_START);

        // The public URL WAS requested: we cannot know it redirects without
        // asking. That is the honest shape of the guarantee.
        self::assertTrue($transport->wasRequested(self::PUBLIC_START), 'the public URL is legitimately requested');

        // The protected target was not.
        self::assertFalse($transport->wasRequested(self::PROTECTED));
        self::assertFalse($transport->anyRequestedPathContains('/members'));
        self::assertSame([self::PUBLIC_START], $transport->requestLog(), 'exactly one request was made');

        self::assertFalse($result->ok);
        self::assertStringContainsString('protected path', (string) $result->error);
    }

    #[Test]
    public function aMultiHopRedirectEndingAtAProtectedPathStopsBeforeIt(): void
    {
        // Two innocuous public hops, then the portal. The boundary has to hold
        // at every hop, not only the first.
        $hopOne = self::ORIGIN . 'news/2026/bulletin';
        $hopTwo = self::ORIGIN . 'notices/current';

        $transport = new RecordingHttpTransport()
            ->redirect(self::PUBLIC_START, $hopOne)
            ->redirect($hopOne, $hopTwo)
            ->redirect($hopTwo, self::PROTECTED)
            ->page(self::PROTECTED, '<html><body>members-only</body></html>');

        new HttpPageFetcher(self::ORIGIN, $transport)->fetch(self::PUBLIC_START);

        self::assertSame(
            [self::PUBLIC_START, $hopOne, $hopTwo],
            $transport->requestLog(),
            'the three public hops were requested and the protected fourth was not',
        );
        self::assertFalse($transport->anyRequestedPathContains('/members'));
    }

    #[Test]
    public function anEncodedProtectedTargetIsAlsoNeverRequested(): void
    {
        // A server that percent-encodes the Location value must not get a
        // request that a literal string comparison would have refused.
        $encoded = self::ORIGIN . '%6Dembers/bulletin';

        $transport = new RecordingHttpTransport()
            ->redirect(self::PUBLIC_START, $encoded)
            ->page($encoded, '<html><body>members-only</body></html>');

        new HttpPageFetcher(self::ORIGIN, $transport)->fetch(self::PUBLIC_START);

        self::assertSame([self::PUBLIC_START], $transport->requestLog());
        self::assertFalse($transport->wasRequested($encoded));
        self::assertFalse($transport->anyRequestedPathContains('members'));
    }

    #[Test]
    public function aValidPublicRedirectIsFollowedAndRequested(): void
    {
        // POSITIVE CONTROL. Without this, every assertion above would pass
        // against a fetcher that refused to request anything at all.
        $publicTarget = self::ORIGIN . 'news/2026/water-advisory';

        $transport = new RecordingHttpTransport()
            ->redirect(self::PUBLIC_START, $publicTarget)
            ->page($publicTarget, '<html><body>water advisory</body></html>');

        $result = new HttpPageFetcher(self::ORIGIN, $transport)->fetch(self::PUBLIC_START);

        self::assertSame([self::PUBLIC_START, $publicTarget], $transport->requestLog());
        self::assertTrue($result->ok, 'a public redirect must be followed');
        self::assertStringContainsString('water advisory', $result->body);
        self::assertSame($publicTarget, $result->finalUrl);
    }

    #[Test]
    public function removingTheRedirectGuardPutsTheProtectedUrlInTheRequestLog(): void
    {
        // MUTATION CONTROL. Proves the assertions above are load-bearing rather
        // than passing because the fixture never reaches a second hop.
        //
        // This fetcher is identical to the production one except that the
        // protected-path check is absent — the single line under test. If its
        // removal did NOT put `/members/bulletin` in the request log, the other
        // tests in this file would be proving nothing.
        $transport = new RecordingHttpTransport()
            ->redirect(self::PUBLIC_START, self::PROTECTED)
            ->page(self::PROTECTED, '<html><body>members-only bulletin</body></html>');

        $unguarded = new UnguardedRedirectFetcher(self::ORIGIN, $transport);
        $unguarded->fetch(self::PUBLIC_START);

        self::assertTrue(
            $transport->wasRequested(self::PROTECTED),
            'without the guard the protected URL IS requested — which is what the guard prevents',
        );
        self::assertSame([self::PUBLIC_START, self::PROTECTED], $transport->requestLog());
    }

    #[Test]
    public function theBoundaryAndTheFetcherAgreeOnWhatIsProtected(): void
    {
        // Guards against the two drifting apart again, which is the shape of
        // the original defect: two lists that disagreed about `/members`.
        foreach (['/members/bulletin', '/portal/files', '/account/settings', '/admin/users'] as $path) {
            self::assertTrue(CrawlBoundary::isProtected(self::ORIGIN . ltrim($path, '/')));

            $transport = new RecordingHttpTransport()
                ->redirect(self::PUBLIC_START, self::ORIGIN . ltrim($path, '/'));
            new HttpPageFetcher(self::ORIGIN, $transport)->fetch(self::PUBLIC_START);

            self::assertSame([self::PUBLIC_START], $transport->requestLog(), $path . ' must not be requested');
        }
    }
}

/**
 * The production fetcher with the protected-path guard removed.
 *
 * Exists only to give {@see RedirectBoundaryTransportTest::removingTheRedirectGuardPutsTheProtectedUrlInTheRequestLog}
 * something to compare against. It is a deliberate copy of `HttpPageFetcher::fetch()`
 * minus one check — kept small and adjacent so the difference is visible.
 */
final class UnguardedRedirectFetcher
{
    public function __construct(
        private readonly string $origin,
        private readonly HttpTransportInterface $transport,
    ) {}

    public function fetch(string $url): void
    {
        $target = $url;

        for ($hop = 0; $hop <= 5; ++$hop) {
            if (!\App\Monitor\UrlNormalizer::isSameOrigin($target, $this->origin)) {
                return;
            }

            // NOTE: no CrawlBoundary::isProtected() check here. That absence is
            // the entire point of this class.

            $response = $this->transport->send($target, 15, 'test', 25 * 1024 * 1024);
            if ($response === null) {
                return;
            }

            [$status, $headers] = [$response[0], $response[1]];
            if (!in_array($status, [301, 302, 303, 307, 308], true) || !isset($headers['location'])) {
                return;
            }

            $next = $headers['location'];
            if (!str_starts_with($next, 'http')) {
                $parts = parse_url($target);
                $next = $parts['scheme'] . '://' . $parts['host'] . (str_starts_with($next, '/') ? $next : '/' . $next);
            }
            if ($next === $target) {
                return;
            }
            $target = $next;
        }
    }
}
