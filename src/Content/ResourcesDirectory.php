<?php

declare(strict_types=1);

namespace App\Content;

use App\Anokii\PayingForSchoolSeedData;
use App\Anokii\TerritorySeedData;
use Waaseyaa\Database\DatabaseInterface;

/**
 * The "Get help" directory, rendered from the Anokii graph so it stays in sync
 * with the Ask box and inherits Place coordinates.
 *
 * Reads the graph straight from the persistent SQLite file (same pattern as
 * GraphRetriever / MarkdownExporter). Scope is the Indigenous FRONT-DOOR services
 * only: the territory commerce services and the paying-for-school funding
 * services are excluded (they belong to the Ask box, not this list), and the
 * crisis helplines are excluded because they are pinned in the crisis banner.
 *
 * Each card carries exactly what the graph holds: website = the service's
 * source_url; phone = the first number found in the curated chunk text (nothing
 * invented); region = the sub-region of its Place; coordinates = the Place
 * lat/lng (a distance-ranking signal only, never shown). A service with no
 * source_url is dropped rather than shown sourceless.
 */
final class ResourcesDirectory
{
    /** Helplines shown in the pinned crisis banner, not as cards. */
    private const array CRISIS_SLUGS = [
        'hope-for-wellness-line', 'talk4healing-line', 'crisis-988-line',
        'nirs-crisis-line', 'mmiwg-crisis-line', 'kids-help-line',
    ];

    /** Place slug => [sub-region slug, label]. Empty place => treaty-wide. */
    private const array SUBREGION = [
        'sault-ste-marie' => ['north-shore', 'North Shore'],
        'blind-river' => ['north-shore', 'North Shore'],
        'elliot-lake' => ['north-shore', 'North Shore'],
        'espanola' => ['north-shore', 'North Shore'],
        'greater-sudbury' => ['north-shore', 'North Shore'],
        'serpent-river' => ['north-shore', 'North Shore'],
        'thessalon' => ['north-shore', 'North Shore'],
        'massey' => ['north-shore', 'North Shore'],
        'webbwood' => ['north-shore', 'North Shore'],
        'spanish' => ['north-shore', 'North Shore'],
        'aundeck-omni-kaning' => ['manitoulin', 'Manitoulin'],
        'mchigeeng' => ['manitoulin', 'Manitoulin'],
        'gore-bay' => ['manitoulin', 'Manitoulin'],
        'mindemoya' => ['manitoulin', 'Manitoulin'],
        'little-current' => ['manitoulin', 'Manitoulin'],
        'wiikwemkoong' => ['manitoulin', 'Manitoulin'],
        'north-bay' => ['nipissing', 'Nipissing / North Bay'],
        'sturgeon-falls' => ['nipissing', 'Nipissing / North Bay'],
        'parry-sound' => ['georgian-bay', 'Georgian Bay / Parry Sound'],
        'henvey-inlet' => ['georgian-bay', 'Georgian Bay / Parry Sound'],
    ];

    private const array TREATY_WIDE = ['treaty-wide', 'Treaty-wide'];

    /** Topic slug => [section label, order]. '' is the friendship-centre group. */
    private const array SECTIONS = [
        'primary-health' => ['Health authorities', 10],
        'mental-health-addictions' => ['Mental health and addictions', 20],
        'community-safety' => ['Policing', 30],
        'legal-aid' => ['Legal and justice', 40],
        'child-and-family' => ['Child and family', 50],
        'education-youth' => ['Education and training', 60],
        'employment-training' => ['Jobs and training', 70],
        'treaty' => ['Treaty governance', 80],
        'housing' => ['Housing', 90],
        'income-support' => ['Income support', 100],
        '' => ['Urban friendship centres', 110],
    ];

    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * Directory grouped by category, ready for the template.
     *
     * @return list<array{topic: string, label: string, cards: list<array<string, mixed>>}>
     */
    public function groups(): array
    {
        $exclude = $this->excludedSlugs();
        $chunks = $this->chunksByService();
        $places = $this->placeCoords();

        $byTopic = [];
        foreach ($this->rows('SELECT name, _data FROM service') as [$name, $data]) {
            $slug = (string) ($data['slug'] ?? '');
            if ($slug === '' || isset($exclude[$slug])) {
                continue;
            }
            $website = (string) ($data['source_url'] ?? '');
            if ($website === '') {
                continue; // never render a sourceless card
            }
            $topic = (string) ($data['has_topic'] ?? '');
            if (!isset(self::SECTIONS[$topic])) {
                continue; // not a front-door directory category
            }
            $place = (string) ($data['located_at'] ?? '');
            [$regionSlug, $regionLabel] = $place !== '' && isset(self::SUBREGION[$place])
                ? self::SUBREGION[$place]
                : self::TREATY_WIDE;
            $chunk = $chunks[$slug] ?? ['text' => '', 'heading' => ''];
            $coord = $places[$place] ?? ['lat' => '', 'lng' => ''];

            $byTopic[$topic][] = [
                'slug' => $slug,
                'name' => $name,
                'region' => $regionLabel,
                'region_slug' => $regionSlug,
                'blurb' => $chunk['heading'] !== '' ? $chunk['heading'] : $this->firstSentence($chunk['text']),
                'phone' => $this->phone($chunk['text']),
                'website' => $website,
                'lat' => $coord['lat'],
                'lng' => $coord['lng'],
            ];
        }

        $groups = [];
        foreach (self::SECTIONS as $topic => [$label]) {
            if (!isset($byTopic[$topic])) {
                continue;
            }
            $cards = $byTopic[$topic];
            usort($cards, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
            $groups[] = ['topic' => $topic, 'label' => $label, 'cards' => $cards];
        }

        return $groups;
    }

    /**
     * Distinct sub-regions present, for the filter (treaty-wide last).
     *
     * @return list<array{slug: string, label: string}>
     */
    public function regions(): array
    {
        $seen = [];
        foreach ($this->groups() as $g) {
            foreach ($g['cards'] as $c) {
                $seen[(string) $c['region_slug']] = (string) $c['region'];
            }
        }
        uksort($seen, static fn(string $a, string $b): int => ($a === 'treaty-wide' ? 1 : 0) <=> ($b === 'treaty-wide' ? 1 : 0) ?: strcmp($a, $b));

        return array_map(static fn(string $slug, string $label): array => ['slug' => $slug, 'label' => $label], array_keys($seen), array_values($seen));
    }

    /**
     * Categories present, for the filter (in section order).
     *
     * @return list<array{slug: string, label: string}>
     */
    public function categories(): array
    {
        $out = [];
        foreach ($this->groups() as $g) {
            $out[] = ['slug' => $g['topic'] !== '' ? $g['topic'] : 'friendship-centres', 'label' => $g['label']];
        }

        return $out;
    }

    // ---- internals -------------------------------------------------------------

    /** @return array<string, true> */
    private function excludedSlugs(): array
    {
        $out = [];
        foreach (self::CRISIS_SLUGS as $slug) {
            $out[$slug] = true;
        }
        foreach ([...TerritorySeedData::services(), ...PayingForSchoolSeedData::services()] as $svc) {
            $out[(string) ($svc['slug'] ?? '')] = true;
        }

        return $out;
    }

    /** @return array<string, array{text: string, heading: string}> */
    private function chunksByService(): array
    {
        $out = [];
        foreach ($this->rows('SELECT title, _data FROM doc_chunk') as [, $data]) {
            if ((string) ($data['entity_type'] ?? '') !== 'service') {
                continue;
            }
            $id = (string) ($data['entity_id'] ?? '');
            if ($id !== '' && !isset($out[$id])) {
                $out[$id] = ['text' => (string) ($data['text'] ?? ''), 'heading' => (string) ($data['heading'] ?? '')];
            }
        }

        return $out;
    }

    /** @return array<string, array{lat: string, lng: string}> */
    private function placeCoords(): array
    {
        $out = [];
        foreach ($this->rows('SELECT name, _data FROM place') as [, $data]) {
            $slug = (string) ($data['slug'] ?? '');
            if ($slug !== '') {
                $out[$slug] = ['lat' => (string) ($data['lat'] ?? ''), 'lng' => (string) ($data['lng'] ?? '')];
            }
        }

        return $out;
    }

    /** First phone number in the text, or '' (carries exactly what is there). */
    private function phone(string $text): string
    {
        return preg_match('/(?:1-)?\d{3}-\d{3}-\d{4}/', $text, $m) === 1 ? $m[0] : '';
    }

    private function firstSentence(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $pos = strpos($text, '. ');

        return $pos !== false ? substr($text, 0, $pos + 1) : $text;
    }

    /**
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function rows(string $sql): array
    {
        $out = [];
        try {
            foreach ($this->db->query($sql) as $row) {
                $values = array_values($row);
                $data = json_decode((string) ($row['_data'] ?? ''), true);
                if (is_array($data)) {
                    $out[] = [(string) ($values[0] ?? ''), $data];
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }
}
