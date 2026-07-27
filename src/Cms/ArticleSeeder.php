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
     * @return array{created: int, skipped: int, unpublished: int, refreshed: int}
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

        $articles = ArticleSeedData::all($this->projectRoot);
        $articlesBySlug = [];
        foreach ($articles as $article) {
            $articlesBySlug[(string) $article['slug']] = $article;
        }

        $existing = [];
        foreach ($this->nodes->findBy(['type' => ArticleFields::BUNDLE]) as $node) {
            $existing[(string) $node->get('slug')] = $node;
        }

        $unpublished = 0;
        foreach (ArticleSeedData::unpublishedSlugs() as $slug) {
            $published = $this->nodes->findBy([
                'type' => ArticleFields::BUNDLE,
                'slug' => $slug,
                'status' => true,
            ], limit: 1);
            $node = $published[0] ?? null;
            if ($node === null) {
                continue;
            }

            $node->set('status', false);
            $node->setRevisionLog('Unpublished by editorial direction.');
            $this->nodes->save($node);
            $unpublished++;
        }

        $refreshed = 0;
        $refreshFields = [
            'social_image',
            'social_image_alt',
            'social_image_width',
            'social_image_height',
        ];
        foreach (ArticleSeedData::metadataRefreshSlugs() as $slug) {
            $node = $existing[$slug] ?? null;
            $article = $articlesBySlug[$slug] ?? null;
            if ($node === null || $article === null) {
                continue;
            }

            $changed = false;
            foreach ($refreshFields as $field) {
                if ($node->get($field) === $article[$field]) {
                    continue;
                }
                $node->set($field, $article[$field]);
                $changed = true;
            }

            if ($changed) {
                $node->setRevisionLog('Updated the published social card.');
                $this->nodes->save($node);
                $refreshed++;
            }
        }

        $created = 0;
        $skipped = 0;
        foreach ($articles as $article) {
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

        return [
            'created' => $created,
            'skipped' => $skipped,
            'unpublished' => $unpublished,
            'refreshed' => $refreshed,
        ];
    }
}
