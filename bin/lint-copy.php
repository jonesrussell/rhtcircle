<?php

declare(strict_types=1);

/*
 * House-style guardrail for the RHT Members' Transparency Circle.
 *
 * Hard rule: NO em dashes (U+2014) anywhere in published copy. Run before every
 * commit/deploy:  php bin/lint-copy.php
 *
 * Exits non-zero (and lists every hit) if an em dash is found in templates/.
 * The other hard rules (non-partisan, sourced facts framed as questions, name no
 * private individuals) are editorial review rules, not mechanically checkable.
 */

$root = \dirname(__DIR__);
$scanDirs = [$root . '/templates'];
$emDash = "\xE2\x80\x94"; // U+2014
$hits = [];

foreach ($scanDirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
            continue;
        }
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
        foreach ($lines as $n => $line) {
            if (str_contains($line, $emDash)) {
                $rel = ltrim(str_replace($root, '', $file->getPathname()), '\\/');
                $hits[] = sprintf('  %s:%d  %s', $rel, $n + 1, trim($line));
            }
        }
    }
}

if ($hits !== []) {
    fwrite(STDERR, "Em dash (U+2014) found in copy. Use commas, colons, or new sentences instead.\n");
    fwrite(STDERR, implode("\n", $hits) . "\n");
    exit(1);
}

fwrite(STDOUT, "Copy lint passed: no em dashes in templates/.\n");
exit(0);
