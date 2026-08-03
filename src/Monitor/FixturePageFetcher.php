<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * A fetcher backed by an in-memory fixture map.
 *
 * Every §9 test drives the collector through this, so the whole suite runs with
 * **no network access whatsoever** — the production Sagamok site is never
 * contacted by a test run, by construction rather than by convention.
 *
 * @api
 */
final class FixturePageFetcher implements PageFetcherInterface
{
    /** @var array<string, FetchResult> Normalized URL => canned result. */
    private array $pages = [];

    /** @var list<string> Every URL this fetcher was asked for, in order. */
    private array $requested = [];

    /**
     * @param array<string, string> $pages Normalized URL => HTML body.
     */
    public static function withPages(array $pages): self
    {
        $fetcher = new self();
        foreach ($pages as $url => $body) {
            $fetcher->set($url, FetchResult::success(200, $url, $body));
        }

        return $fetcher;
    }

    public function set(string $url, FetchResult $result): self
    {
        $this->pages[UrlNormalizer::normalize($url)] = $result;

        return $this;
    }

    public function remove(string $url): self
    {
        unset($this->pages[UrlNormalizer::normalize($url)]);

        return $this;
    }

    /** @return list<string> */
    public function requestedUrls(): array
    {
        return $this->requested;
    }

    public function fetch(string $url): FetchResult
    {
        $normalized = UrlNormalizer::normalize($url);
        $this->requested[] = $normalized;

        // An unknown URL is a 404, not an exception — the collector treats it
        // as "absent this run", which is what the two-run rule is for.
        return $this->pages[$normalized]
            ?? FetchResult::success(404, $normalized, '');
    }
}
