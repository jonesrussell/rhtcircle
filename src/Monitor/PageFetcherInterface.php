<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * Fetches one public page.
 *
 * The seam exists so the collector is testable against fixtures with no network
 * at all — every test in §9 drives {@see FixturePageFetcher}, and the production
 * fetcher is never constructed in a test run.
 *
 * **Scope is fixed by the interface, not by policy.** There is no credential
 * parameter, no cookie jar, no auth header, and no archive or search-index
 * method. A members-portal collector cannot be written against this contract
 * without changing the contract, which is the point: the boundary is structural
 * rather than a rule someone must remember.
 *
 * @api
 */
interface PageFetcherInterface
{
    /**
     * Fetch $url, following same-origin redirects only.
     *
     * Implementations must not throw on an HTTP error or a timeout — a fetch
     * failure is source-health information (spec §4.3), not an exception that
     * aborts the run and strands every later item.
     */
    public function fetch(string $url): FetchResult;
}
