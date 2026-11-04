<?php

namespace App\Services\Embeddings;

final readonly class EmbeddingResult
{
    public function __construct(
        /** @var list<list<float>> one vector per input text, in input order */
        public array $vectors,
        /** Tokens billed by the provider, summed over all requests. */
        public int $totalTokens,
        public string $model,
    ) {}
}
