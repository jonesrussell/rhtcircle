<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A single extracted retrieval chunk before it is persisted as a doc_chunk
 * entity. Plain value object so the chunker stays pure and testable.
 */
final readonly class ChunkData
{
    public function __construct(
        public string $chunkKey,
        public string $sourceUrl,
        public string $title,
        public string $heading,
        public string $text,
    ) {}
}
