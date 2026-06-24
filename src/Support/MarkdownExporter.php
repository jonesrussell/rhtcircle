<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Http\ContentNegotiation\MediaTypeAcceptNegotiator;
use Waaseyaa\Seo\Llms\LlmsTopic;
use Waaseyaa\Seo\Llms\LlmsTxtGenerator;

/**
 * The site's machine-readable Markdown layer, advertised in /llms.txt.
 *
 * rhtcircle is a hand-authored plain-Twig app: it does not mount the framework's
 * SSR entity router, so the framework's default agent surface (/{type}/{id}
 * ?format=md, and a generic /llms.txt) never resolved here. This exporter
 * implements the surface from rhtcircle's real content instead:
 *
 *   - content pages return clean Markdown for ?format=md (or Accept:
 *     text/markdown), built from the same heading-delimited doc_chunk corpus the
 *     chat reads, so the Markdown tracks the page;
 *   - graph entities (place/community/organization/service/project/topic and the
 *     doc_chunk itself) return Markdown by slug or id;
 *   - /llms.txt lists the real pages and primary entities with real titles and
 *     canonical URLs.
 *
 * Reads run as raw SELECTs against the persistent SQLite file (the same pattern
 * as {@see \Anokii\CoIntelligence\GraphRetriever}), so a route-build-time
 * ephemeral connection never starves them and a missing table degrades to an
 * empty result rather than a 500.
 */
final class MarkdownExporter
{
    private const string BASE_URL = 'https://rhtcircle.ca';

    /** Primary graph entity types listed in /llms.txt, in display order. */
    private const array LISTED_TYPES = [
        'place' => 'Places',
        'organization' => 'Organizations',
        'service' => 'Services',
        'project' => 'Projects',
        'topic' => 'Topics',
    ];

    /** Entity types that may be fetched as Markdown at /{type}/{key}?format=md. */
    private const array ENTITY_TYPES = ['place', 'community', 'organization', 'service', 'project', 'topic', 'doc_chunk'];

    public function __construct(private readonly DatabaseInterface $db) {}

    /** True when the request prefers Markdown (?format=md|markdown|raw, or Accept: text/markdown). */
    public function wantsMarkdown(Request $request): bool
    {
        $negotiator = new MediaTypeAcceptNegotiator();
        $supported = [MediaTypeAcceptNegotiator::HTML, MediaTypeAcceptNegotiator::MARKDOWN];

        $override = $negotiator->resolveQueryOverride($request->query->all(), $supported);
        $chosen = $override ?? $negotiator->negotiate(
            (string) $request->headers->get('Accept', ''),
            $supported,
            MediaTypeAcceptNegotiator::HTML,
        );

        return $chosen === MediaTypeAcceptNegotiator::MARKDOWN;
    }

    public static function isEntityType(string $type): bool
    {
        return \in_array($type, self::ENTITY_TYPES, true);
    }

    // ---- Pages -----------------------------------------------------------------

    public function pageResponse(string $path): Response
    {
        $markdown = $this->pageMarkdown($path);

        return $markdown === null ? $this->notFound() : $this->markdown($markdown);
    }

    /** Markdown for a content page, assembled from its doc_chunks (null if none). */
    public function pageMarkdown(string $path): ?string
    {
        $chunks = array_values(array_filter(
            $this->loadChunks(),
            static fn(array $c): bool => $c['source_url'] === $path,
        ));
        if ($chunks === []) {
            return null;
        }

        $title = $this->cleanTitle($chunks[0]['title']);
        $lines = ['# ' . $title, ''];
        foreach ($chunks as $chunk) {
            if ($chunk['heading'] !== '') {
                $lines[] = '## ' . $chunk['heading'];
                $lines[] = '';
            }
            if ($chunk['text'] !== '') {
                $lines[] = $chunk['text'];
                $lines[] = '';
            }
        }
        $lines[] = '---';
        $lines[] = 'Source: ' . self::BASE_URL . $path;

        return implode("\n", $lines) . "\n";
    }

    // ---- Entities --------------------------------------------------------------

    public function entityResponse(string $type, string $key): Response
    {
        if (!self::isEntityType($type)) {
            return $this->notFound();
        }
        $markdown = $this->entityMarkdown($type, $key);

        return $markdown === null ? $this->notFound() : $this->markdown($markdown);
    }

    /** Markdown for a graph entity, by slug (preferred) or numeric id. */
    public function entityMarkdown(string $type, string $key): ?string
    {
        $row = $this->findEntity($type, $key);
        if ($row === null) {
            return null;
        }
        [$name, $data] = $row;
        $slug = (string) ($data['slug'] ?? $key);

        $lines = ['# ' . ($name !== '' ? $name : $slug), ''];
        $lines[] = '- Type: ' . $this->humanize($type);
        foreach ($this->entityFacts($type, $data) as $fact) {
            $lines[] = '- ' . $fact;
        }
        $lines[] = '';

        // A doc_chunk carries its own heading + passage text.
        if ($type === 'doc_chunk') {
            $heading = trim((string) ($data['heading'] ?? ''));
            if ($heading !== '') {
                $lines[] = '## ' . $heading;
                $lines[] = '';
            }
            $text = trim((string) ($data['text'] ?? ''));
            if ($text !== '') {
                $lines[] = $text;
                $lines[] = '';
            }
        }

        // Append any grounding chunks linked to this entity (services, projects,
        // and the place gaps carry their sourced text in doc_chunks).
        foreach ($this->loadChunks() as $chunk) {
            if ($chunk['entity_type'] === $type && $chunk['entity_id'] === $slug && $chunk['text'] !== '') {
                if ($chunk['heading'] !== '') {
                    $lines[] = '## ' . $chunk['heading'];
                    $lines[] = '';
                }
                $lines[] = $chunk['text'];
                $lines[] = '';
            }
        }

        $source = (string) ($data['source_url'] ?? '');
        $lines[] = '---';
        $lines[] = 'Source: ' . ($source !== '' ? $source : self::BASE_URL . '/' . $type . '/' . rawurlencode($slug));

        return implode("\n", $lines) . "\n";
    }

    /**
     * Human-readable facts for an entity, by type.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function entityFacts(string $type, array $data): array
    {
        $facts = [];
        $str = static fn(string $k): string => is_scalar($data[$k] ?? null) ? trim((string) $data[$k]) : '';
        $list = static function (string $k) use ($data): array {
            $v = $data[$k] ?? null;
            $v = is_array($v) ? $v : json_decode((string) $v, true);

            return is_array($v) ? array_values(array_map(strval(...), $v)) : [];
        };

        if ($str('located_at') !== '') {
            $facts[] = 'Located in: ' . $str('located_at');
        }
        if ($str('has_topic') !== '') {
            $facts[] = 'Topic: ' . $str('has_topic');
        }
        if ($str('provided_by') !== '') {
            $facts[] = 'Provided by: ' . $str('provided_by');
        }
        if ($type === 'topic' && $str('keywords') !== '') {
            $facts[] = 'Keywords: ' . $str('keywords');
        }
        if ($type === 'community' && $list('region') !== []) {
            $facts[] = 'Region: ' . implode(', ', $list('region'));
        }
        if ($type === 'project' && $list('relates_to') !== []) {
            $facts[] = 'Relates to: ' . implode(', ', $list('relates_to'));
        }
        if ($type === 'place' && $str('travel_note') !== '') {
            $facts[] = 'Travel: ' . $str('travel_note');
        }

        return $facts;
    }

    // ---- llms.txt --------------------------------------------------------------

    public function llmsTxtResponse(): Response
    {
        $generator = new LlmsTxtGenerator();
        $topics = [];

        // Pages: every published site page, from the ingested corpus.
        $pageLinks = [];
        foreach ($this->sitePages() as $path => $title) {
            $pageLinks[] = ['title' => $this->cleanTitle($title), 'url' => self::BASE_URL . $path . '?format=md'];
        }
        if ($pageLinks !== []) {
            $topics[] = new LlmsTopic('pages', 'Pages', 'Site pages as Markdown.', $pageLinks);
        }

        // Primary graph entities (the resource directory). doc_chunks (the
        // retrieval substrate, 500+) are intentionally not enumerated; the
        // 21 nations are covered by the /communities pages above.
        foreach (self::LISTED_TYPES as $type => $label) {
            $links = [];
            foreach ($this->entityIndex($type) as [$slug, $name]) {
                $links[] = [
                    'title' => $name !== '' ? $name : $slug,
                    'url' => self::BASE_URL . '/' . $type . '/' . rawurlencode($slug) . '?format=md',
                ];
            }
            if ($links !== []) {
                $topics[] = new LlmsTopic($type, $label, sprintf('%s as Markdown.', $label), $links);
            }
        }

        $body = $generator->generate(
            'Robinson Huron Treaty',
            'Machine-readable index of the Robinson Huron Treaty resource hub for AI agents. Each linked URL returns clean Markdown. Add ?format=md (or send Accept: text/markdown) to any page.',
            $topics,
        );

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    // ---- Data access (raw SELECTs on the persistent file) ----------------------

    /**
     * Distinct site pages from the corpus: source_url => title. Only on-site
     * paths (leading "/"); curated chunks point at external org URLs and are
     * excluded. First title seen for a path wins (the page title).
     *
     * @return array<string, string>
     */
    private function sitePages(): array
    {
        $pages = [];
        foreach ($this->loadChunks() as $chunk) {
            $url = $chunk['source_url'];
            if ($url === '' || $url[0] !== '/' || isset($pages[$url])) {
                continue;
            }
            $pages[$url] = $chunk['title'];
        }
        ksort($pages);

        return $pages;
    }

    /**
     * Slug + label for every entity of a type, slug-sorted.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function entityIndex(string $type): array
    {
        $out = [];
        foreach ($this->rows(sprintf('SELECT name, _data FROM %s', $type)) as [$name, $data]) {
            $slug = (string) ($data['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $out[$slug] = [$slug, $name];
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * Find one entity by slug (preferred) or numeric id.
     *
     * @return array{0: string, 1: array<string, mixed>}|null [name, data]
     */
    private function findEntity(string $type, string $key): ?array
    {
        // doc_chunk's label column is `title`; the graph entities use `name`.
        $labelColumn = $type === 'doc_chunk' ? 'title' : 'name';
        $numeric = ctype_digit($key);
        foreach ($this->rows(sprintf('SELECT id, %s, _data FROM %s', $labelColumn, $type)) as [$id, $label, $data]) {
            if ((string) ($data['slug'] ?? '') === $key || ($numeric && (string) $id === $key)) {
                return [(string) $label, $data];
            }
        }

        return null;
    }

    /**
     * @return list<array{source_url: string, title: string, heading: string, text: string, entity_type: string, entity_id: string}>
     */
    private function loadChunks(): array
    {
        $chunks = [];
        foreach ($this->rows('SELECT id, title, _data FROM doc_chunk ORDER BY id') as [, $title, $data]) {
            $chunks[] = [
                'source_url' => (string) ($data['source_url'] ?? ''),
                'title' => $title,
                'heading' => (string) ($data['heading'] ?? ''),
                'text' => (string) ($data['text'] ?? ''),
                'entity_type' => (string) ($data['entity_type'] ?? ''),
                'entity_id' => (string) ($data['entity_id'] ?? ''),
            ];
        }

        return $chunks;
    }

    /**
     * Run a SELECT whose first column is a label and last column is `_data`
     * JSON; return [col0, ..., decodedData] per row. A missing table yields
     * nothing rather than throwing.
     *
     * @return list<array<int, mixed>>
     */
    private function rows(string $sql): array
    {
        $out = [];
        try {
            foreach ($this->db->query($sql) as $row) {
                $values = array_values($row);
                $data = json_decode((string) ($row['_data'] ?? end($values)), true);
                if (!is_array($data)) {
                    continue;
                }
                $values[array_key_last($values)] = $data;
                $out[] = $values;
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    private function markdown(string $body): Response
    {
        return new Response($body, 200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    private function notFound(): Response
    {
        return new Response("Not found.\n", 404, ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    private function cleanTitle(string $raw): string
    {
        $title = preg_replace('/\s*·\s*(Robinson Huron Treaty|RHT.*)$/u', '', $raw) ?? $raw;

        return trim($title) !== '' ? trim($title) : $raw;
    }

    private function humanize(string $type): string
    {
        return ucwords(str_replace('_', ' ', $type));
    }
}
