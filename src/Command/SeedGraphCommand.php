<?php

declare(strict_types=1);

namespace App\Command;

use Anokii\Entity\Community;
use Anokii\Entity\DocChunk;
use Anokii\Entity\GraphEntityBase;
use Anokii\Entity\Organization;
use Anokii\Entity\Place;
use Anokii\Entity\Project;
use Anokii\Entity\Service;
use Anokii\Entity\Topic;
use App\Anokii\GraphSeedData;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * `bin/waaseyaa app:seed-graph [--dry-run]`: seed the RHT relational graph
 * (Places, the 21 Communities with curated regions, Topics, front-door
 * Organizations and Services, and the Projects), upsert the curated service
 * chunks, and link each ingested community-page chunk to its community.
 *
 * Idempotent: every entity is keyed by a stable slug (chunks by chunk_key), so a
 * re-run updates rather than duplicates. All data is public and sourced. Run
 * after app:ingest so the page chunks exist to link.
 */
final class SeedGraphCommand
{
    /**
     * @param array<string, EntityRepositoryInterface> $repos keyed by entity type id
     */
    public function __construct(private readonly array $repos) {}

    private ?SymfonyCommandIO $io = null;

    public function run(SymfonyCommandIO $io): int
    {
        $this->io = $io;
        $dryRun = (bool) $io->option('dry-run');

        $topics = GraphSeedData::topics();
        $places = GraphSeedData::places();
        $communities = GraphSeedData::communities();
        $organizations = GraphSeedData::organizations();
        $services = GraphSeedData::services();
        $projects = GraphSeedData::projects();
        $curated = GraphSeedData::curatedChunks();

        if ($dryRun) {
            $io->writeln(sprintf(
                'Dry run: would seed %d topics, %d places, %d communities, %d organizations, %d services, %d projects, %d curated chunks, then link community-page chunks.',
                count($topics), count($places), count($communities), count($organizations), count($services), count($projects), count($curated),
            ));

            return 0;
        }

        $io->writeln('Seeding RHT graph...');
        $io->writeln(sprintf('  topics:        %s', $this->sync('topic', $topics, static fn(array $v): Topic => Topic::make($v))));
        $io->writeln(sprintf('  places:        %s', $this->sync('place', $places, static fn(array $v): Place => Place::make($v))));
        $io->writeln(sprintf('  communities:   %s', $this->sync('community', $communities, static fn(array $v): Community => Community::make($v))));
        $io->writeln(sprintf('  organizations: %s', $this->sync('organization', $organizations, static fn(array $v): Organization => Organization::make($v))));
        $io->writeln(sprintf('  services:      %s', $this->sync('service', $services, static fn(array $v): Service => Service::make($v))));
        $io->writeln(sprintf('  projects:      %s', $this->sync('project', $projects, static fn(array $v): Project => Project::make($v))));
        $io->writeln(sprintf('  curated chunks: %s', $this->syncCuratedChunks($curated)));

        $linked = $this->linkCommunityPageChunks();
        $io->writeln(sprintf('Linked %d community-page chunks to their community.', $linked));

        return 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): GraphEntityBase $make
     */
    private function sync(string $type, array $rows, callable $make): string
    {
        $repo = $this->repos[$type];
        $existing = [];
        foreach ($repo->findBy([]) as $entity) {
            if ($entity instanceof GraphEntityBase) {
                $existing[$entity->getSlug()] = $entity;
            }
        }

        $created = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            $current = $existing[$slug] ?? null;
            try {
                if ($current instanceof GraphEntityBase) {
                    foreach ($row as $field => $value) {
                        $current->set($field, $value);
                    }
                    $repo->save($current);
                    $updated++;
                    continue;
                }
                $repo->save($make($row));
                $created++;
            } catch (\Waaseyaa\Entity\Validation\EntityValidationException $e) {
                $this->io->writeln('  VALIDATION FAIL ' . $type . ' "' . $slug . '":');
                foreach ($e->violations as $v) {
                    $this->io->writeln('    [' . $v->getPropertyPath() . '] ' . $v->getMessage());
                }
                throw $e;
            }
        }

        return sprintf('%d created, %d updated', $created, $updated);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function syncCuratedChunks(array $rows): string
    {
        $repo = $this->repos['doc_chunk'];
        $existing = [];
        foreach ($repo->findBy([]) as $chunk) {
            if ($chunk instanceof DocChunk) {
                $existing[$chunk->getChunkKey()] = $chunk;
            }
        }

        $created = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $key = (string) $row['chunk_key'];
            $current = $existing[$key] ?? null;
            if ($current instanceof DocChunk) {
                foreach ($row as $field => $value) {
                    $current->set($field, $value);
                }
                $repo->save($current);
                $updated++;
                continue;
            }
            $repo->save(DocChunk::make($row));
            $created++;
        }

        return sprintf('%d created, %d updated', $created, $updated);
    }

    /**
     * Link each ingested chunk whose source_url is /communities/<slug> (for a known
     * community) to that community, so its provenance points at a graph entity.
     * Curated chunks (already linked to a service) and other pages are left as is.
     */
    private function linkCommunityPageChunks(): int
    {
        $communitySlugs = [];
        foreach (GraphSeedData::communities() as $c) {
            $communitySlugs[(string) $c['slug']] = true;
        }

        $repo = $this->repos['doc_chunk'];
        $linked = 0;
        foreach ($repo->findBy([]) as $chunk) {
            if (!$chunk instanceof DocChunk) {
                continue;
            }
            if (str_starts_with($chunk->getChunkKey(), GraphSeedData::CURATED_KEY_PREFIX)) {
                continue;
            }
            $url = $chunk->getSourceUrl();
            if (preg_match('#^/communities/([a-z0-9-]+)$#', $url, $m) === 1 && isset($communitySlugs[$m[1]])) {
                $chunk->set('entity_type', 'community');
                $chunk->set('entity_id', $m[1]);
                $repo->save($chunk);
                $linked++;
            }
        }

        return $linked;
    }
}
