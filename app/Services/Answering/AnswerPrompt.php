<?php

namespace App\Services\Answering;

use App\Services\OpenAi\JsonSchema;
use App\Services\Retrieval\RetrievedChunk;

/**
 * Everything needed for one answer request. `sources` maps each label used in
 * the prompt (S1, S2, ...) to the chunk it stands for, so citations returned by
 * the model can be checked against the exact text that was sent.
 */
final readonly class AnswerPrompt
{
    public function __construct(
        public string $instructions,
        public string $input,
        public JsonSchema $schema,
        /** @var array<string, RetrievedChunk> */
        public array $sources,
    ) {}
}
