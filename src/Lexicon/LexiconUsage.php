<?php

declare(strict_types=1);

namespace App\Lexicon;

/**
 * The governance / usage block Minoo returns alongside a lookup.
 *
 * Carries the OCAP governance statement and the noncommercial terms so the page
 * can state, plainly, that the content is community-governed and shared on
 * noncommercial terms. Rendered as context, never as an endorsement.
 */
final readonly class LexiconUsage
{
    public function __construct(
        public string $governance,
        public bool $communityGoverned,
        public bool $noncommercial,
        public ?string $license,
        public string $terms,
        public ?string $referenceUrl,
    ) {}

    /**
     * @param array<string, mixed> $usage the upstream `usage` block
     */
    public static function fromArray(array $usage): self
    {
        $reference = is_array($usage['reference'] ?? null) ? $usage['reference'] : [];
        $referenceUrl = trim((string) ($reference['url'] ?? ''));
        $license = $usage['license'] ?? null;

        return new self(
            governance: trim((string) ($usage['governance'] ?? '')),
            communityGoverned: (bool) ($usage['community_governed'] ?? false),
            noncommercial: (bool) ($usage['noncommercial'] ?? false),
            license: ($license === null || $license === '') ? null : (string) $license,
            terms: trim((string) ($usage['terms'] ?? '')),
            referenceUrl: $referenceUrl === '' ? null : $referenceUrl,
        );
    }
}
