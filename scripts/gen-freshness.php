<?php

declare(strict_types=1);

/**
 * Generate data/freshness.json: a per-page-type "last updated" date taken from
 * the git commit history of each page type's CONTENT source. This is the only
 * honest, non-fabricated freshness signal available to the renderer: the runtime
 * container has no git (the image clones shallow then deletes .git), and there is
 * no maintained human-verification date in the data, so we capture the real
 * last-change date here, at commit time, and bake it into a committed manifest
 * the renderer reads (App\Support\View::last_updated()).
 *
 * Each key maps to one or more source files; the date is the most recent commit
 * touching any of them (the content's true last change, not a deploy timestamp).
 *
 * Re-run this after changing any content source, then commit the updated JSON:
 *   php scripts/gen-freshness.php
 */

$root = \dirname(__DIR__);

/** key => content source file(s) whose last commit dates the page type. */
$sources = [
    // Community profiles render from Nations.php.
    'nations' => ['src/Content/Nations.php'],
    // Land project profiles render from LandProjects.php.
    'land' => ['src/Content/LandProjects.php'],
    // The resources directory renders from the front-door graph seed(s).
    'resources' => [
        'src/Anokii/GraphSeedData.php',
        'src/Anokii/TerritorySeedData.php',
        'src/Anokii/PayingForSchoolSeedData.php',
    ],
    // The Paying for school page is fed by its own seed.
    'paying-for-school' => ['src/Anokii/PayingForSchoolSeedData.php'],
];

/** Last commit date (YYYY-MM-DD) touching $file, or null if unknown. */
$commitDate = static function (string $file) use ($root): ?string {
    $cmd = 'git -C ' . escapeshellarg($root) . ' log -1 --format=%cs -- ' . escapeshellarg($file) . ' 2>NUL';
    $out = trim((string) shell_exec($cmd));

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $out) === 1 ? $out : null;
};

$manifest = [];
foreach ($sources as $key => $files) {
    $latest = null;
    foreach ($files as $file) {
        $date = $commitDate($file);
        if ($date !== null && ($latest === null || $date > $latest)) {
            $latest = $date;
        }
    }
    if ($latest !== null) {
        $manifest[$key] = $latest;
    }
}

if ($manifest === []) {
    fwrite(STDERR, "gen-freshness: no dates resolved (is this a git checkout?)\n");
    exit(1);
}

@mkdir($root . '/data', 0775, true);
$path = $root . '/data/freshness.json';
file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "Wrote {$path}:\n";
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
