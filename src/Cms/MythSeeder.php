<?php

declare(strict_types=1);

namespace App\Cms;

use App\Content\MythEntries;
use App\Entity\MythEntry;
use App\Entity\SourceLink;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Idempotent migration of App\Content\MythEntries into the managed myth_entry +
 * source_link content types. Keyed by slug (myths) and a deterministic owner
 * token (sources), so re-running updates in place rather than duplicating. The
 * seed writes the default (English) translation; Anishinaabemowin translations
 * are added later through the admin, not here.
 */
final class MythSeeder
{
    public function __construct(
        private readonly EntityRepositoryInterface $myths,
        private readonly EntityRepositoryInterface $sources,
    ) {}

    /**
     * @return array{myths: int, sources: int}
     */
    public function seed(): array
    {
        $existingMyth = [];
        foreach ($this->myths->findBy([]) as $m) {
            $existingMyth[(string) $m->get('slug')] = $m;
        }
        $existingSrc = [];
        foreach ($this->sources->findBy([]) as $s) {
            $existingSrc[(string) $s->id()] = $s;
        }

        $mythCount = 0;
        $srcCount = 0;
        $weight = 0;

        foreach (MythEntries::keyed() as $slug => $e) {
            $weight += 10;
            $fields = [
                'slug' => $slug,
                'question' => (string) ($e['question'] ?? ''),
                'answer' => (string) ($e['answer'] ?? ''),
                'record' => (string) ($e['record'] ?? ''),
                'takeaway' => (string) ($e['takeaway'] ?? ''),
                'weight' => $weight,
            ];

            $current = $existingMyth[$slug] ?? null;
            if ($current !== null) {
                foreach ($fields as $f => $v) {
                    $current->set($f, $v);
                }
                $this->myths->save($current);
            } else {
                $this->myths->save(new MythEntry($fields + [
                    'id' => $slug,
                    'uuid' => 'myth:' . $slug,
                    'langcode' => 'en',
                    'default_langcode' => 'en',
                ]));
            }
            $mythCount++;

            // Sources: replace this owner's set wholesale (small, and keeps order
            // and deletions correct without per-row diffing).
            $owner = 'myth_entry:' . $slug;
            foreach ($existingSrc as $sid => $s) {
                if ((string) $s->get('owner') === $owner) {
                    $this->sources->delete($s);
                    unset($existingSrc[$sid]);
                }
            }
            $i = 0;
            foreach (($e['sources'] ?? []) as $src) {
                $i += 10;
                $sid = $slug . '-src-' . $i;
                $this->sources->save(new SourceLink([
                    'id' => $sid,
                    'uuid' => 'src:' . $sid,
                    'owner' => $owner,
                    'label' => (string) ($src['label'] ?? ''),
                    'url' => (string) ($src['url'] ?? ''),
                    'weight' => $i,
                    'langcode' => 'en',
                    'default_langcode' => 'en',
                ]));
                $srcCount++;
            }
        }

        return ['myths' => $mythCount, 'sources' => $srcCount];
    }
}
