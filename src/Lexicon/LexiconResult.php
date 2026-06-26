<?php

declare(strict_types=1);

namespace App\Lexicon;

/**
 * The normalized result of one lexicon lookup.
 *
 * Two ways to build one:
 *  - {@see self::fromUpstream()} parses a real Minoo response (match or miss);
 *    `available` is true even when there are zero matches (a clean miss is a
 *    real answer the corpus simply doesn't have yet).
 *  - {@see self::unavailable()} is the fail-soft result: Minoo was slow, down,
 *    or returned a non-200, so we have no answer. `available` is false and the
 *    page shows a gentle "temporarily unavailable" note instead of breaking.
 *
 * Only `available` results are ever cached; an unavailable result must not be,
 * or a transient Minoo outage would be served for the whole TTL.
 */
final readonly class LexiconResult
{
    /**
     * @param list<LexiconMatch> $matches
     */
    public function __construct(
        public bool $available,
        public string $matchType,
        public string $query,
        public string $tag,
        public string $dir,
        public int $count,
        public array $matches,
        public ?LexiconUsage $usage,
    ) {}

    public function hasMatches(): bool
    {
        return $this->matches !== [];
    }

    /**
     * The fail-soft result: we could not reach a real answer from Minoo. Echoes
     * back the request so the form can keep its state; carries no matches.
     */
    public static function unavailable(string $query, string $tag, string $dir): self
    {
        return new self(
            available: false,
            matchType: 'unavailable',
            query: $query,
            tag: $tag,
            dir: $dir,
            count: 0,
            matches: [],
            usage: null,
        );
    }

    /**
     * Parse a real Minoo lookup response. Tolerant of missing fields so a
     * slightly-changed payload still yields a usable result.
     *
     * @param array<string, mixed> $payload decoded JSON body from Minoo
     */
    public static function fromUpstream(array $payload): self
    {
        $rawMatches = is_array($payload['matches'] ?? null) ? $payload['matches'] : [];
        $matches = [];
        foreach ($rawMatches as $row) {
            if (is_array($row)) {
                $matches[] = LexiconMatch::fromArray($row);
            }
        }

        $usage = is_array($payload['usage'] ?? null) ? LexiconUsage::fromArray($payload['usage']) : null;

        return new self(
            available: true,
            matchType: trim((string) ($payload['match_type'] ?? 'miss')),
            query: trim((string) ($payload['query'] ?? '')),
            tag: trim((string) ($payload['tag'] ?? '')),
            dir: trim((string) ($payload['dir'] ?? '')),
            count: isset($payload['count']) ? (int) $payload['count'] : count($matches),
            matches: $matches,
            usage: $usage,
        );
    }
}
