param([switch]$AllowCandidate)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)
function Invoke-Checked([string]$Program, [string[]]$Arguments) {
    & $Program @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Program failed with exit $LASTEXITCODE" }
}
Invoke-Checked git @('fetch', 'origin', 'main')
if (git status --porcelain) { throw 'Release qualification requires a clean committed candidate.' }
$candidate = git rev-parse HEAD
$canonical = git rev-parse origin/main
Invoke-Checked git @('merge-base', '--is-ancestor', 'origin/main', 'HEAD')
if (!$AllowCandidate -and $candidate -ne $canonical) { throw 'HEAD must equal GitHub main. Use -AllowCandidate only for pre-landing qualification.' }
Write-Output "Candidate: $candidate; GitHub main: $canonical"
Invoke-Checked composer @('check')
Invoke-Checked php @('vendor/bin/waaseyaa', 'app:ingest', '--dry-run')
Invoke-Checked php @('vendor/bin/waaseyaa', 'field-access:preflight')
if ((git status --porcelain) -or (git rev-parse HEAD) -ne $candidate) {
    throw 'Candidate changed during qualification. Commit it and qualify the new identity.'
}
Invoke-Checked git @('fetch', 'origin', 'main')
Invoke-Checked git @('merge-base', '--is-ancestor', 'origin/main', 'HEAD')
Write-Output 'Local checks passed. Linux image and production-snapshot qualification are still required before promotion.'
