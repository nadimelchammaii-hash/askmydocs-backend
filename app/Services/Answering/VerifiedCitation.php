<?php

namespace App\Services\Answering;

final readonly class VerifiedCitation
{
    public function __construct(
        public int $chunkId,
        public int $pageNumber,
        /** The quote as returned by the model; verified to appear in the chunk. */
        public string $quote,
    ) {}
}
