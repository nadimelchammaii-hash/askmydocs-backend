<?php

namespace App\Services\OpenAi;

/**
 * The model's answer, decoded from JSON that follows the requested schema.
 * Its content is model output: validate it before trusting it.
 */
final readonly class StructuredOutput
{
    public function __construct(
        /** @var array<string, mixed> */
        public array $data,
        public string $model,
        public int $inputTokens,
        /** Includes reasoning tokens (they are billed as output). */
        public int $outputTokens,
        public int $durationMs,
    ) {}
}
