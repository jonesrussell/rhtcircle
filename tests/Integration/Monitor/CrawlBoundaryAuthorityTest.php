<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * There is exactly ONE list of protected path literals.
 *
 * The original boundary defect was not a wrong list — it was two lists.
 * `LinkExtractor::NEVER_FOLLOW` knew about `/members`;
 * `GateDetector::LOGIN_PATH_MARKERS` did not, and the gate is what runs after a
 * fetch. Both lists were individually defensible. Their disagreement is what
 * let a redirect into the portal be hashed, snapshotted and titled.
 *
 * A test that only checks the boundary's behaviour cannot catch that class of
 * regression: a second list added tomorrow would pass every behavioural test
 * until the day the two drifted. So this asserts the structural property
 * directly — protected path literals are defined in `CrawlBoundary` and
 * nowhere else.
 */
final class CrawlBoundaryAuthorityTest extends TestCase
{
    private const MONITOR_SRC = __DIR__ . '/../../../src/Monitor';

    /**
     * Path literals that name a members-only or authentication surface. A
     * second copy of any of these outside CrawlBoundary is the beginning of
     * the next divergence.
     */
    private const PROTECTED_LITERALS = [
        '/members', '/member-portal', '/portal', '/account', '/admin',
        '/login', '/signin', '/sign-in', '/wp-admin', '/wp-login',
        '/sso', '/saml', '/dashboard',
    ];

    #[Test]
    public function protectedPathLiteralsLiveOnlyInTheCrawlBoundary(): void
    {
        $offenders = [];

        foreach ($this->monitorSources() as $file) {
            if (basename($file) === 'CrawlBoundary.php') {
                continue;
            }

            $code = $this->stripCommentsAndDocblocks((string) file_get_contents($file));

            foreach (self::PROTECTED_LITERALS as $literal) {
                // Quoted string literal only — a comment mentioning /members is
                // documentation, not a competing authority.
                if (str_contains($code, "'" . $literal . "'") || str_contains($code, '"' . $literal . '"')) {
                    $offenders[] = basename($file) . ' defines ' . $literal;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Protected path literals must be defined only by CrawlBoundary.\n"
            . "A second list is how the original defect happened: two lists that were each\n"
            . "correct in isolation and disagreed about /members.\n"
            . implode("\n", $offenders),
        );
    }

    #[Test]
    public function theArchitectureTestDetectsASecondPathList(): void
    {
        // MUTATION CONTROL. Without this, the assertion above would pass just
        // as happily against a scanner that reads nothing, matches nothing, or
        // was quietly pointed at an empty directory.
        $fixture = sys_get_temp_dir() . '/rival_boundary_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($fixture, <<<'PHP'
            <?php
            final class RivalGate
            {
                private const MARKERS = ['/members', '/account'];
            }
            PHP);

        try {
            $code = $this->stripCommentsAndDocblocks((string) file_get_contents($fixture));
            $hits = [];
            foreach (self::PROTECTED_LITERALS as $literal) {
                if (str_contains($code, "'" . $literal . "'")) {
                    $hits[] = $literal;
                }
            }

            self::assertContains('/members', $hits, 'the detector must see a rival list');
            self::assertContains('/account', $hits);
        } finally {
            @unlink($fixture);
        }
    }

    #[Test]
    public function commentsMentioningProtectedPathsAreNotFlagged(): void
    {
        // The other half of the control: the detector must not be so blunt that
        // it forbids explaining the boundary in prose. Several files legitimately
        // describe why /members matters.
        $code = $this->stripCommentsAndDocblocks(<<<'PHP'
            <?php
            // A redirect to /members must never be fetched.
            /** See /members handling in CrawlBoundary. */
            final class Explanatory { }
            PHP);

        self::assertStringNotContainsString('/members', $code);
    }

    // ------------------------------------------------------------------

    /** @return list<string> */
    private function monitorSources(): array
    {
        $files = glob(self::MONITOR_SRC . '/*.php') ?: [];
        self::assertNotEmpty($files, 'the scanner must actually find monitor sources');

        return $files;
    }

    private function stripCommentsAndDocblocks(string $php): string
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
