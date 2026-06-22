<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turns a rendered HTML page into stable, heading-delimited retrieval chunks.
 * Pure and deterministic: the same input yields the same chunks and the same
 * stable keys, so ingestion can upsert on the key rather than duplicate. No
 * storage, no embeddings.
 *
 * HTML strategy: extract text from the first <main> (falling back to <body>),
 * skipping script/style/svg/noscript. Each h1/h2/h3 starts a new section; text
 * before the first heading becomes the intro section. Sections longer than
 * MAX_CHARS are split on word boundaries into "part 0", "part 1", ...
 *
 * Ported from the OIATC implementation; only the stored-title suffix strip is
 * rhtcircle-specific.
 */
final class DocChunker
{
    /** Soft cap on chunk length; oversized sections split on word boundaries. */
    public const MAX_CHARS = 1500;

    /** Drop fragments shorter than this (stray labels, empty sections). */
    public const MIN_CHARS = 30;

    /**
     * @return list<ChunkData>
     */
    public function chunkHtml(string $html, string $sourceUrl): array
    {
        $dom = $this->loadHtml($html);
        $title = $this->extractTitle($dom);
        $root = $this->contentRoot($dom);
        if ($root === null) {
            return [];
        }

        /** @var list<array{heading: string, text: string}> $sections */
        $sections = [];
        $current = ['heading' => '', 'buffer' => ''];

        $flush = static function (array &$sections, array &$current): void {
            $text = self::normalize($current['buffer']);
            if ($text !== '') {
                $sections[] = ['heading' => $current['heading'], 'text' => $text];
            }
            $current['buffer'] = '';
        };

        $this->walk($root, $sections, $current, $flush);
        $flush($sections, $current);

        return $this->sectionsToChunks($sections, $sourceUrl, $title);
    }

    /**
     * @param list<array{heading: string, text: string}> $sections
     *
     * @return list<ChunkData>
     */
    private function sectionsToChunks(array $sections, string $sourceUrl, string $title): array
    {
        $chunks = [];
        $usedKeys = [];

        foreach ($sections as $section) {
            $parts = $this->splitToSize($section['text']);
            foreach ($parts as $partIndex => $partText) {
                if (mb_strlen($partText) < self::MIN_CHARS) {
                    continue;
                }
                $key = $this->chunkKey($sourceUrl, $section['heading'], $partIndex, $usedKeys);
                $chunks[] = new ChunkData(
                    chunkKey: $key,
                    sourceUrl: $sourceUrl,
                    title: $title,
                    heading: $section['heading'],
                    text: $partText,
                );
            }
        }

        return $chunks;
    }

    /**
     * @param array{heading: string, text: string}[] $sections
     * @param array{heading: string, buffer: string} $current
     */
    private function walk(\DOMNode $node, array &$sections, array &$current, callable $flush): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'svg', 'noscript', 'template'], true)) {
                    continue;
                }
                if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
                    $flush($sections, $current);
                    $current['heading'] = self::normalize($child->textContent);
                    continue;
                }
                $this->walk($child, $sections, $current, $flush);
                continue;
            }
            if ($child instanceof \DOMText) {
                $current['buffer'] .= ' ' . $child->wholeText;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function splitToSize(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return [$text];
        }

        $parts = [];
        $buffer = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = $buffer === '' ? $word : $buffer . ' ' . $word;
            if (mb_strlen($candidate) > self::MAX_CHARS && $buffer !== '') {
                $parts[] = $buffer;
                $buffer = $word;
                continue;
            }
            $buffer = $candidate;
        }
        if ($buffer !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }

    /**
     * Stable, human-readable key: {url}#{heading-slug}-{part}.
     *
     * @param array<string, true> $usedKeys
     */
    private function chunkKey(string $sourceUrl, string $heading, int $partIndex, array &$usedKeys): string
    {
        $base = $sourceUrl . '#' . ($heading === '' ? 'intro' : $this->slug($heading)) . '-' . $partIndex;
        $key = $base;
        $dup = 1;
        while (isset($usedKeys[$key])) {
            $key = $base . '_' . (++$dup);
        }
        $usedKeys[$key] = true;

        return $key;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return mb_substr($slug, 0, 80);
    }

    private static function normalize(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function loadHtml(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    private function extractTitle(\DOMDocument $dom): string
    {
        $titleNode = $dom->getElementsByTagName('title')->item(0);
        if ($titleNode !== null) {
            $title = self::normalize($titleNode->textContent);
            // Strip the rhtcircle site suffix for a cleaner stored title.
            $title = preg_replace('/\s*·\s*Robinson Huron Treaty.*$/u', '', $title) ?? $title;
            if ($title !== '') {
                return $title;
            }
        }
        $h1 = $dom->getElementsByTagName('h1')->item(0);

        return $h1 !== null ? self::normalize($h1->textContent) : '';
    }

    private function contentRoot(\DOMDocument $dom): ?\DOMNode
    {
        $main = $dom->getElementsByTagName('main')->item(0);
        if ($main !== null) {
            return $main;
        }

        return $dom->getElementsByTagName('body')->item(0);
    }
}
