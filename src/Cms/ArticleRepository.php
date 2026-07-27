<?php

declare(strict_types=1);

namespace App\Cms;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ListingResolver;

/**
 * Public read model for managed articles.
 *
 * Collections resolve through Waaseyaa Listing. Direct slug lookup is reserved
 * for the article detail route.
 */
final class ArticleRepository
{
    public const string LISTING_ALL = 'rht_articles_published';
    public const string LISTING_PROMOTED = 'rht_articles_promoted';
    public const string LISTING_SAGAMOK = 'rht_articles_sagamok';

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ListingDefinitionRegistry $definitions,
        private readonly ListingResolver $resolver,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function published(): array
    {
        return $this->listing(self::LISTING_ALL);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function promoted(): array
    {
        return $this->listing(self::LISTING_PROMOTED);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forSagamok(): array
    {
        return $this->listing(self::LISTING_SAGAMOK);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublished(string $slug): ?array
    {
        $rows = $this->entityTypeManager->getRepository('node')->findBy([
            'type' => ArticleFields::BUNDLE,
            'slug' => $slug,
            'status' => true,
        ], limit: 1);

        return isset($rows[0]) ? $this->view($rows[0]) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listing(string $id): array
    {
        $rows = [];
        foreach ($this->resolver->resolve($this->definitions->get($id))->rows as $entity) {
            if ($entity instanceof EntityInterface) {
                $rows[] = $this->view($entity);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function view(EntityInterface $node): array
    {
        $slug = (string) $node->get('slug');

        return [
            'id' => (string) $node->id(),
            'slug' => $slug,
            'href' => '/news/' . $slug,
            'url' => '/news/' . $slug,
            'internal' => true,
            'title' => (string) $node->get('title'),
            'community_slug' => (string) $node->get('community_slug'),
            'nations' => [(string) $node->get('community_slug')],
            'kicker' => (string) $node->get('kicker'),
            'topic' => (string) $node->get('section'),
            'deck' => (string) $node->get('deck'),
            'summary' => (string) $node->get('summary'),
            'author' => (string) $node->get('author'),
            'date' => (string) $node->get('date_display'),
            'date_iso' => (string) $node->get('date_iso'),
            'section' => (string) $node->get('section'),
            'source' => 'RHT Circle',
            'action' => (string) $node->get('action_label'),
            'og_description' => (string) $node->get('og_description'),
            'social_image' => (string) $node->get('social_image'),
            'social_image_alt' => (string) $node->get('social_image_alt'),
            'social_image_width' => (int) $node->get('social_image_width'),
            'social_image_height' => (int) $node->get('social_image_height'),
            'image' => [
                'src' => (string) $node->get('hero_src'),
                'width' => (int) $node->get('hero_width'),
                'height' => (int) $node->get('hero_height'),
                'alt' => (string) $node->get('hero_alt'),
            ],
            'hero' => [
                'src' => (string) $node->get('hero_src'),
                'width' => (int) $node->get('hero_width'),
                'height' => (int) $node->get('hero_height'),
                'alt' => (string) $node->get('hero_alt'),
                'caption' => (string) $node->get('hero_caption'),
            ],
            'body_html' => (string) $node->get('body_html'),
            'sidebar_html' => (string) $node->get('sidebar_html'),
            'sources_html' => (string) $node->get('sources_html'),
        ];
    }
}
