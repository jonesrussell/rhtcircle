<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * Bounded breadth-first discovery of same-origin public URLs (spec §0).
 *
 * The monitor exists to notice **new** public posts. A frozen URL list would
 * report edits to pages someone wrote down once and stay silent about
 * everything published since, so the crawl starts from configured seed pages
 * and follows public same-origin links outward.
 *
 * Bounded on every axis that could otherwise become a burden on someone else's
 * server: the 300-URL per-run ceiling, a depth limit, same-origin only, and the
 * fetcher's own one-request-per-second throttle between hops.
 *
 * Discovery reuses the collector's fetcher, so the crawl inherits every limit
 * and refusal that fetcher enforces — including that it cannot follow a
 * redirect off-origin, and cannot authenticate to anything.
 */
final class PublicSiteCrawler
{
    public function __construct(private readonly PageFetcherInterface $fetcher) {}

    /**
     * @param list<string> $seedUrls
     * @return array{urls: list<string>, fetched: int, truncated: bool}
     */
    public function discover(array $seedUrls, string $origin, int $maxUrls, int $maxDepth): array
    {
        $queue = [];
        $seen = [];

        foreach ($seedUrls as $seed) {
            if (!UrlNormalizer::isSameOrigin($seed, $origin)) {
                continue;
            }
            $key = UrlNormalizer::itemKey($seed);
            if (!isset($seen[$key])) {
                $seen[$key] = UrlNormalizer::normalize($seed);
                $queue[] = ['url' => $seed, 'depth' => 0];
            }
        }

        $fetched = 0;
        $truncated = false;

        while ($queue !== []) {
            $current = array_shift($queue);

            if (count($seen) >= $maxUrls) {
                $truncated = true;
                break;
            }
            if ($current['depth'] >= $maxDepth) {
                // Known, but not expanded: it is still monitored, we simply do
                // not follow further from it.
                continue;
            }

            $result = $this->fetcher->fetch($current['url']);
            ++$fetched;

            // A page we could not read, or one that is gated or not for
            // retention, contributes no links. Deliberate: following links out
            // of a login shell is how a public-site crawler wanders somewhere
            // it should not be.
            if (!$result->ok || $result->statusCode !== 200) {
                continue;
            }
            if (GateDetector::isExcluded($result->statusCode, $result->finalUrl, $result->body, $result->headers)) {
                continue;
            }

            foreach (LinkExtractor::extract($result->body, $result->finalUrl, $origin) as $link) {
                $key = UrlNormalizer::itemKey($link);
                if (isset($seen[$key])) {
                    continue;
                }
                if (count($seen) >= $maxUrls) {
                    $truncated = true;
                    break;
                }
                $seen[$key] = $link;
                $queue[] = ['url' => $link, 'depth' => $current['depth'] + 1];
            }
        }

        return [
            'urls' => array_values($seen),
            'fetched' => $fetched,
            'truncated' => $truncated,
        ];
    }
}
