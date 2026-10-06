<?php

declare(strict_types=1);

namespace App\Content;

use App\Cms\ArticleRepository;

/** Shared editorial read context for HTTP rendering and local corpus ingestion. */
final class PublicationContext
{
    public function __construct(private readonly ?ArticleRepository $articles = null) {}

    /**
     * Publication front page with current reporting and the 21 community desks.
     * @return array<string, mixed>
     */
    public function home(): array
    {
        $nationNames = Nations::names();

        return [
            'stories' => NewsFeed::frontPage([...($this->articles?->published() ?? []), ...NewsFeed::recentExternalStories()]),
            'regions' => Nations::regions(),
            'communities_by_region' => Nations::byRegion(),
            'nation_names' => $nationNames,
        ];
    }

    /**
     * Original RHT Circle reporting and a hand-reviewed external news digest.
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function news(array $query = []): array
    {
        $nationNames = Nations::names();

        $articles = $this->articles?->published() ?? [];
        $allStories = [...$articles, ...NewsFeed::recentExternalStories()];
        $nationInput = $query['nation'] ?? '';
        $topicInput = $query['topic'] ?? '';
        $nation = is_string($nationInput) && ($nationInput === 'treaty-wide' || isset($nationNames[$nationInput])) ? $nationInput : '';
        $topics = array_values(array_unique(array_column($allStories, 'topic')));
        sort($topics);
        $topic = is_string($topicInput) && in_array($topicInput, $topics, true) ? $topicInput : '';
        $browse = $this->articles?->browse($nation, $topic);
        $pagination = $browse['pagination'] ?? null;
        $filters = array_filter(['nation' => $nation, 'topic' => $topic], static fn (string $value): bool => $value !== '');

        return [
            'stories' => NewsFeed::filter(NewsFeed::recentExternalStories(), $nation, $topic),
            'feature_article' => null,
            'reporting_articles' => $browse['articles'] ?? [],
            'pagination' => $pagination,
            'previous_page_url' => $pagination?->hasPrev ? '/news?' . http_build_query($filters + ['page' => $pagination->page - 1]) : null,
            'next_page_url' => $pagination?->hasNext ? '/news?' . http_build_query($filters + ['page' => $pagination->page + 1]) : null,
            'selected_nation' => $nation,
            'selected_topic' => $topic,
            'topics' => $topics,
            'regions' => Nations::regions(),
            'communities_by_region' => Nations::byRegion(),
            'nation_names' => $nationNames,
        ];
    }

    /** @param array<string, mixed> $nation
     *  @param array{total: int, online: int, paper: int} $signatures
     *  @return array<string, mixed>
     */
    public function community(array $nation, array $signatures): array
    {
        $slug = (string) $nation['slug'];

        return [
            'nation' => $nation,
            ...CommunityHub::context($slug, $nation, $signatures),
            'local_reporting' => $this->articles?->browse($slug)['articles'] ?? [],
        ];
    }
}
