<?php

declare(strict_types=1);

/*
 * House-style guardrail for the Circle: no hardcoded signature-count literal
 * may appear in a template. Run before every commit/deploy:
 *   php bin/lint-signature-counts.php
 *
 * Why: the records-request illustration caption was a hand-typed number
 * ("88 people"), bumped by commit each time signatures grew. A bad deploy
 * pin (July 2026) froze it two revisions stale while the DB kept growing,
 * producing a page that visibly contradicted its own live sign-up counter.
 * The awaiting-council page and the community hub card had the same defect
 * independently. All three were converted to read PetitionRepository::
 * signatureBreakdown() at render time; this lint keeps a hardcoded number
 * from reappearing in any template, this page or a future one.
 *
 * Detection: strip Twig {{ ... }} expressions (those are live-computed, not
 * literals) from each line, then flag two narrow, specific shapes: a digit
 * directly followed by "signature(s)" ("113 signatures", "88 plus
 * signatures"), and a digit followed by "people" with "signed" nearby
 * ("88 people ... has signed", the illustration-caption shape). Deliberately
 * narrow: a generic "digit near the word sign" scan also matches treaty
 * dates ("signed on September 9, 1850"), unrelated uses of "sign"/"signed",
 * and numbered list items, none of which are signature-count bugs. A short,
 * explicit ALLOWLIST covers the remaining genuine historical facts (a past
 * filing's count, on the date it was filed) and mentions of unrelated
 * petitions, so it never fires on real history, only on a new hardcoded
 * live-count-shaped number.
 *
 * Exits non-zero (and lists every hit) if a hardcoded signature figure is
 * found in templates/ that is not on the allowlist below.
 */

$root = \dirname(__DIR__);
$scanDir = $root . '/templates';

// file (relative to templates/) => list of distinctive substrings that are
// allowed to contain a digit near "sign". Each is a genuine historical fact
// or an unrelated petition, not a live total that can go stale the way a
// current signature count can.
$allowlist = [
    'pages/standard/records-request.html.twig' => [
        'with 28 member and 2 supporter signatures and a 30-day response requested',
    ],
    'pages/land/massey-solar-project/voices.html.twig' => [
        'gathered over 1,600 signatures',
    ],
];

$hits = [];

if (is_dir($scanDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
            continue;
        }
        $rel = ltrim(str_replace($root . '/templates/', '', $file->getPathname()), '\\/');
        $allowedSnippets = $allowlist[$rel] ?? [];

        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
        foreach ($lines as $n => $line) {
            // Twig expressions are live-computed; strip them before looking
            // for hardcoded digits.
            $stripped = preg_replace('/\{\{.*?\}\}/', '', $line);

            if (!preg_match('/\d/', $stripped)) {
                continue;
            }
            // Narrow, specific shapes only: "113 signatures" / "88 plus
            // signatures", or "88 people ... has signed" (the illustration
            // caption shape). Not a generic digit-near-"sign" scan, that also
            // matches treaty dates and unrelated uses of "sign"/"signed".
            $isCountPattern = preg_match('/\d[\d,]*\s+(?:plus\s+)?signatures?\b/i', $stripped) === 1
                || preg_match('/\d[\d,]*\s+people\b.{0,60}\bsigned\b/i', $stripped) === 1;
            if (!$isCountPattern) {
                continue;
            }

            $allowed = false;
            foreach ($allowedSnippets as $snippet) {
                if (str_contains($line, $snippet)) {
                    $allowed = true;
                    break;
                }
            }
            if ($allowed) {
                continue;
            }

            $hits[] = sprintf('  %s:%d  %s', $rel, $n + 1, trim($line));
        }
    }
}

if ($hits !== []) {
    fwrite(STDERR, "Hardcoded signature-count literal found in a template.\n");
    fwrite(STDERR, "Pull the number from PetitionRepository::signatureBreakdown() (server-side context)\n");
    fwrite(STDERR, "or the /api/petition/{slug} endpoint (client-side), the way every other signature\n");
    fwrite(STDERR, "count on the site does. If this is a genuine historical fact (a past filing's count,\n");
    fwrite(STDERR, "not a current total), add it to the allowlist in bin/lint-signature-counts.php.\n\n");
    fwrite(STDERR, implode("\n", $hits) . "\n");
    exit(1);
}

fwrite(STDOUT, "Signature-count lint passed: no hardcoded signature literals in templates/.\n");
exit(0);
