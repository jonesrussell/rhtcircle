<?php

declare(strict_types=1);

namespace App\Command;

use Anokii\Entity\DocChunk;
use App\Anokii\GraphSeedData;
use App\Content\LandProjects;
use App\Content\Nations;
use App\Support\ChunkData;
use App\Support\DocChunker;
use App\Support\View;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * `bin/waaseyaa app:ingest [--dry-run] [--no-prune]`: render the hub's own pages
 * and upsert their heading-delimited passages into the doc_chunk entity as the
 * Co-Intelligence retrieval corpus, so answers come from real pages and cite them.
 *
 * Sources: the 21 community profiles, /treaty and its distribution-models page,
 * /land and the Massey cluster, /land/territory-and-safety (community safety),
 * /standard and the records request, /treaty-wide, /circle, and /about.
 *
 * Idempotent: chunks are keyed by a stable chunk_key; a re-run updates unchanged
 * keys, inserts new ones, and (unless --no-prune) deletes page chunks not seen
 * this run, so the corpus converges to the current pages. Curated service chunks
 * (owned by app:seed-graph) are never pruned here. No embeddings.
 */
final class IngestCommand
{
    /**
     * Fixed-content pages to ingest, as source URL => Twig template. The 21
     * community pages are added dynamically from the Nations content layer.
     *
     * @var array<string, string>
     */
    private const PAGES = [
        '/treaty' => 'pages/treaty/index.html.twig',
        '/treaty/distribution-models' => 'pages/treaty/distribution-models.html.twig',
        '/treaty/language' => 'pages/treaty/language.html.twig',
        '/treaty/settlement-where-it-goes' => 'pages/treaty/settlement-where-it-goes.html.twig',
        '/myth-versus-record' => 'pages/myth-versus-record.html.twig',
        '/treaty-wide' => 'pages/treaty-wide.html.twig',
        '/standard' => 'pages/standard.html.twig',
        '/standard/records-request' => 'pages/standard/records-request.html.twig',
        '/land' => 'pages/land/index.html.twig',
        '/land/massey-solar-project' => 'pages/land/massey-solar-project.html.twig',
        '/land/massey-solar-project/what-youve-heard' => 'pages/land/massey-solar-project/what-youve-heard.html.twig',
        '/land/massey-solar-project/voices' => 'pages/land/massey-solar-project/voices.html.twig',
        '/land/massey-solar-project/climate' => 'pages/land/massey-solar-project/climate.html.twig',
        '/safety' => 'pages/safety/index.html.twig',
        '/safety/get-help-now' => 'pages/safety/get-help-now.html.twig',
        '/safety/emergency-preparedness' => 'pages/safety/emergency-preparedness.html.twig',
        '/safety/missing-persons-and-mmiwg' => 'pages/safety/missing-persons-and-mmiwg.html.twig',
        '/safety/harm-reduction' => 'pages/safety/harm-reduction.html.twig',
        '/safety/protecting-elders' => 'pages/safety/protecting-elders.html.twig',
        '/safety/information-safety' => 'pages/safety/information-safety.html.twig',
        '/safety/hate-and-extremism' => 'pages/safety/hate-and-extremism.html.twig',
        '/resources' => 'pages/resources/index.html.twig',
        '/communities/sagamok/how-its-organized' => 'pages/communities/sagamok/how-its-organized.html.twig',
        '/communities/sagamok/members-website-issue' => 'pages/communities/sagamok/members-website-issue.html.twig',
        '/circle' => 'pages/circle/index.html.twig',
        '/about' => 'pages/about.html.twig',
    ];

    public function __construct(
        private readonly EntityRepositoryInterface $chunks,
        private readonly DocChunker $chunker = new DocChunker(),
    ) {}

    public function run(SymfonyCommandIO $io): int
    {
        $dryRun = (bool) $io->option('dry-run');
        $pruneOption = $io->option('prune');
        $prune = $pruneOption === null ? true : (bool) $pruneOption;

        [$chunks, $sources] = $this->collectChunks($io);
        $io->writeln(sprintf('Extracted %d chunks from %d pages.', count($chunks), $sources));

        if ($dryRun) {
            foreach (array_slice($chunks, 0, 10) as $c) {
                $io->writeln(sprintf('  [%s] "%s" (%d chars)', $c->sourceUrl, $c->heading !== '' ? $c->heading : '(intro)', mb_strlen($c->text)));
            }
            $io->writeln('Dry run: no changes written.');

            return 0;
        }

        $result = $this->syncChunks($chunks, $prune);
        $io->writeln(sprintf(
            'doc_chunk sync: %d created, %d updated, %d deleted (%d page chunks this run).',
            $result['created'], $result['updated'], $result['deleted'], $result['total'],
        ));

        return 0;
    }

    /**
     * @return array{0: list<ChunkData>, 1: int}
     */
    private function collectChunks(SymfonyCommandIO $io): array
    {
        $chunks = [];
        $sources = 0;

        $render = function (string $sourceUrl, string $template, array $context) use (&$chunks, &$sources, $io): void {
            try {
                $html = View::render($template, $context);
            } catch (\Throwable $e) {
                $io->error(sprintf('Skipped %s: %s', $sourceUrl, $e->getMessage()));

                return;
            }
            $pageChunks = $this->chunker->chunkHtml($html, $sourceUrl);
            if ($pageChunks !== []) {
                $sources++;
            }
            foreach ($pageChunks as $c) {
                $chunks[] = $c;
            }
        };

        foreach (self::PAGES as $sourceUrl => $template) {
            $render($sourceUrl, $template, []);
        }

        // The Land project pages are data-driven: render each profile through the
        // shared template. (Massey keeps its own templates, ingested via PAGES above.)
        foreach (LandProjects::all() as $project) {
            $render('/land/' . (string) $project['slug'], 'pages/land/project.html.twig', ['project' => $project]);
        }

        // The 21 community pages are data-driven: render each nation's profile.
        foreach (Nations::all() as $nation) {
            $slug = (string) $nation['slug'];
            $template = $slug === 'sagamok'
                ? 'pages/communities/sagamok.html.twig'
                : 'pages/communities/nation.html.twig';
            $render('/communities/' . $slug, $template, ['nation' => $nation]);
        }

        return [$chunks, $sources];
    }

    /**
     * Upsert chunks by stable key and (optionally) prune page chunks not seen this
     * run. Curated service chunks (CURATED_KEY_PREFIX) are owned by app:seed-graph
     * and are never created or pruned here.
     *
     * @param list<ChunkData> $chunks
     *
     * @return array{created: int, updated: int, deleted: int, total: int}
     */
    private function syncChunks(array $chunks, bool $prune): array
    {
        $byKey = [];
        foreach ($this->chunks->findBy([]) as $existing) {
            if ($existing instanceof DocChunk) {
                $byKey[$existing->getChunkKey()] = $existing;
            }
        }

        $seen = [];
        $created = 0;
        $updated = 0;
        foreach ($chunks as $c) {
            $seen[$c->chunkKey] = true;
            $existing = $byKey[$c->chunkKey] ?? null;
            if ($existing instanceof DocChunk) {
                $existing->set('source_url', $c->sourceUrl);
                $existing->set('title', $c->title);
                $existing->set('heading', $c->heading);
                $existing->set('text', $c->text);
                $this->chunks->save($existing);
                $updated++;
                continue;
            }
            $this->chunks->save(DocChunk::make([
                'chunk_key' => $c->chunkKey,
                'source_url' => $c->sourceUrl,
                'title' => $c->title,
                'heading' => $c->heading,
                'text' => $c->text,
            ]));
            $created++;
        }

        $deleted = 0;
        if ($prune) {
            foreach ($byKey as $key => $existing) {
                if (str_starts_with($key, GraphSeedData::CURATED_KEY_PREFIX)) {
                    continue;
                }
                if (!isset($seen[$key])) {
                    $this->chunks->delete($existing);
                    $deleted++;
                }
            }
        }

        return ['created' => $created, 'updated' => $updated, 'deleted' => $deleted, 'total' => count($chunks)];
    }
}
