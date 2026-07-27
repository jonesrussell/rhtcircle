<?php

declare(strict_types=1);

namespace App\Cms;

use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeType;

/**
 * Creates the article bundle and missing migrated articles.
 *
 * Existing slugs are deliberately left untouched. CMS edits and revisions are
 * authoritative after the initial conversion.
 */
final class ArticleSeeder
{
    public function __construct(
        private readonly EntityRepositoryInterface $nodes,
        private readonly EntityRepositoryInterface $nodeTypes,
        private readonly string $projectRoot,
    ) {}

    /**
     * @return array{created: int, skipped: int, unpublished: int}
     */
    public function seed(): array
    {
        if ($this->nodeTypes->find(ArticleFields::BUNDLE) === null) {
            $this->nodeTypes->save(new NodeType([
                'type' => ArticleFields::BUNDLE,
                'name' => 'Article',
                'description' => 'Original RHT Circle reporting and analysis.',
                'new_revision' => true,
                'display_submitted' => true,
                'status' => true,
            ]));
        }

        $existing = [];
        foreach ($this->nodes->findBy(['type' => ArticleFields::BUNDLE]) as $node) {
            $existing[(string) $node->get('slug')] = $node;
        }

        $unpublished = 0;
        foreach (ArticleSeedData::unpublishedSlugs() as $slug) {
            $node = $existing[$slug] ?? null;
            if ($node === null || !(bool) $node->get('status')) {
                continue;
            }

            $node->set('status', false);
            $node->setRevisionLog('Unpublished by editorial direction.');
            $this->nodes->save($node);
            $unpublished++;
        }

        $created = 0;
        $skipped = 0;
        foreach (ArticleSeedData::all($this->projectRoot) as $article) {
            $slug = (string) $article['slug'];
            if (isset($existing[$slug])) {
                $skipped++;
                continue;
            }

            $createdAt = strtotime((string) $article['date_iso'] . ' 12:00:00 UTC');
            $node = new Node($article + [
                'type' => ArticleFields::BUNDLE,
                'status' => true,
                'created' => $createdAt === false ? time() : $createdAt,
                'changed' => $createdAt === false ? time() : $createdAt,
            ]);
            $node->setRevisionLog('Migrated from the original hand-authored article.');
            $node->enforceIsNew();
            $this->nodes->save($node);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'unpublished' => $unpublished];
    }
}
