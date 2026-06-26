<?php

declare(strict_types=1);

namespace App\Lexicon;

/**
 * One Anishinaabemowin word returned by Minoo's lexicon lookup.
 *
 * A tolerant value object: {@see self::fromArray()} reads Minoo's `matches[]`
 * shape and fills sensible defaults for any missing field, so a partial or
 * slightly-changed upstream payload degrades to a usable row rather than an
 * exception. The attribution fields are carried through verbatim from the
 * corpus provenance and MUST be rendered with the word (OCAP, community-governed).
 */
final readonly class LexiconMatch
{
    /**
     * @param list<string> $definitions
     */
    public function __construct(
        public string $word,
        public array $definitions,
        public string $tag,
        public string $dialect,
        public string $label,
        public string $slug,
        public string $matchType,
        public int $matchScore,
        public string $matchedOn,
        public string $attribution,
        public string $attributionSource,
        public ?string $sourceUrl,
    ) {}

    /**
     * @param array<string, mixed> $row one entry of the upstream `matches[]`
     */
    public static function fromArray(array $row): self
    {
        $definition = $row['definition'] ?? [];
        if (is_string($definition)) {
            $definition = [$definition];
        }
        $definitions = [];
        if (is_array($definition)) {
            foreach ($definition as $value) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $definitions[] = $text;
                }
            }
        }

        $provenance = is_array($row['provenance'] ?? null) ? $row['provenance'] : [];
        $sourceUrl = trim((string) ($provenance['source_url'] ?? ''));

        return new self(
            word: trim((string) ($row['word'] ?? '')),
            definitions: $definitions,
            tag: trim((string) ($row['tag'] ?? '')),
            dialect: trim((string) ($row['dialect'] ?? '')),
            label: trim((string) ($row['label'] ?? '')),
            slug: trim((string) ($row['slug'] ?? '')),
            matchType: trim((string) ($row['match_type'] ?? '')),
            matchScore: (int) ($row['match_score'] ?? 0),
            matchedOn: trim((string) ($row['matched_on'] ?? '')),
            attribution: trim((string) ($provenance['attribution'] ?? '')),
            attributionSource: trim((string) ($provenance['attribution_source'] ?? '')),
            sourceUrl: $sourceUrl === '' ? null : $sourceUrl,
        );
    }
}
