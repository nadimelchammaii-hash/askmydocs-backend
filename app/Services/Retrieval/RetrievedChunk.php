<?php

namespace App\Services\Retrieval;

/**
 * A chunk found for a question. `content` comes from an uploaded PDF:
 * untrusted data, never instructions.
 */
final readonly class RetrievedChunk
{
    public function __construct(
        public int $chunkId,
        public string $documentId,
        public int $pageNumber,
        public int $chunkIndex,
        public string $content,
        /** Cosine similarity: 1 = same direction (very relevant), 0 = unrelated. */
        public float $similarity,
    ) {}
}
