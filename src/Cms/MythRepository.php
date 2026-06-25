<?php

declare(strict_types=1);

namespace App\Cms;

use Waaseyaa\Entity\EntityTypeManager;

/**
 * Read side of the managed myth_entry content type: loads the entries (and their
 * source_link citations) in display order and returns them in the array shape the
 * partials/myth_versus_record.html.twig component expects, so the template is
 * unchanged from the days it read App\Content\MythEntries.
 *
 * Field reads go through the entity, so when Anishinaabemowin translations exist
 * they resolve through the active-language fallback chain (oj-x-<code> -> oj ->
 * en); with only English present today, English renders.
 */
final class MythRepository
{
    public function __construct(private readonly EntityTypeManager $entityTypeManager) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function ordered(): array
    {
        $myths = $this->entityTypeManager->getRepository('myth_entry')->findBy([]);
        usort($myths, static fn ($a, $b): int => (int) $a->get('weight') <=> (int) $b->get('weight'));

        $sourcesByOwner = [];
        foreach ($this->entityTypeManager->getRepository('source_link')->findBy([]) as $s) {
            $sourcesByOwner[(string) $s->get('owner')][] = $s;
        }

        $out = [];
        foreach ($myths as $m) {
            $owner = 'myth_entry:' . (string) $m->get('slug');
            $sources = $sourcesByOwner[$owner] ?? [];
            usort($sources, static fn ($a, $b): int => (int) $a->get('weight') <=> (int) $b->get('weight'));

            $out[] = [
                'question' => (string) $m->get('question'),
                'answer' => (string) $m->get('answer'),
                'record' => (string) $m->get('record'),
                'takeaway' => (string) $m->get('takeaway'),
                'sources' => array_map(
                    static fn ($s): array => ['label' => (string) $s->get('label'), 'url' => (string) $s->get('url')],
                    $sources,
                ),
            ];
        }

        return $out;
    }
}
