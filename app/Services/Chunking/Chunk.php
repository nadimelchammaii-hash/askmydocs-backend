<?php

namespace App\Services\Chunking;

/**
 * A piece of one page's text, small enough to embed and to show as a citation.
 */
final readonly class Chunk
{
    public function __construct(
        /** 0-based position of the chunk within the whole document. */
        public int $index,
        /** 1-based page the text comes from (a chunk never spans pages). */
        public int $pageNumber,
        /** An exact excerpt of the page text, trimmed. */
        public string $content,
    ) {}

    public function charCount(): int
    {
        return mb_strlen($this->content);
    }
}
