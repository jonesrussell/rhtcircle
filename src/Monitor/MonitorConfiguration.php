<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * Explicit app configuration for the monitor — the spec's open seed decision,
 * resolved here rather than left implicit.
 *
 * Everything the collector needs to know about *what* to watch lives in
 * `config/waaseyaa.php` under `sagamok_monitor`, so switching the monitor on,
 * changing where it starts, or turning it off entirely is a reviewable
 * configuration change rather than a code change.
 *
 * The origin and seed paths are **public website only**. There is no portal
 * setting and no place to put one.
 *
 * @api
 */
final readonly class MonitorConfiguration
{
    /**
     * @param list<string> $seedPaths Same-origin paths the crawl starts from.
     */
    private function __construct(
        public string $sourceKey,
        public string $label,
        public string $originUrl,
        public array $seedPaths,
        public int $maxUrlsPerRun,
        public int $maxCrawlDepth,
        public bool $enabled,
    ) {}

    /**
     * @param array<string, mixed> $config The app config array.
     */
    public static function fromConfig(array $config): self
    {
        /** @var array<string, mixed> $monitor */
        $monitor = $config['sagamok_monitor'] ?? [];

        $seeds = $monitor['seed_paths'] ?? [];

        return new self(
            sourceKey: (string) ($monitor['source_key'] ?? 'sagamok_public_site'),
            label: (string) ($monitor['label'] ?? 'Sagamok public website'),
            originUrl: (string) ($monitor['origin_url'] ?? ''),
            seedPaths: array_values(array_filter(array_map('strval', is_array($seeds) ? $seeds : []))),
            maxUrlsPerRun: (int) ($monitor['max_urls_per_run'] ?? PublicSiteCollector::MAX_URLS_PER_RUN),
            maxCrawlDepth: (int) ($monitor['max_crawl_depth'] ?? 2),
            enabled: (bool) ($monitor['enabled'] ?? false),
        );
    }

    /**
     * Absolute seed URLs.
     *
     * @return list<string>
     */
    public function seedUrls(): array
    {
        if ($this->originUrl === '') {
            return [];
        }

        $origin = rtrim($this->originUrl, '/');
        $urls = [];
        foreach ($this->seedPaths as $path) {
            $urls[] = $origin . '/' . ltrim($path, '/');
        }

        return $urls;
    }

    /**
     * Whether this configuration can actually drive a run.
     *
     * Checked before any request: a half-configured monitor must fail closed
     * rather than run against a default nobody chose.
     */
    public function isRunnable(): bool
    {
        return $this->originUrl !== '' && $this->seedPaths !== [];
    }
}
