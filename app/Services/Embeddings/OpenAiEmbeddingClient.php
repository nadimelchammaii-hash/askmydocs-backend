<?php

namespace App\Services\Embeddings;

use App\Services\OpenAi\OpenAiException;
use App\Services\OpenAi\OpenAiHttp;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * Calls OpenAI's embeddings endpoint (POST /v1/embeddings).
 *
 * - Sends texts in batches (OpenAI allows up to 2,048 inputs and 300,000 tokens
 *   per request; 100 chunks of about 400 tokens stay far below both).
 * - Checks every response: one vector per input, of the configured size.
 * - Authentication, timeouts and error classification: OpenAiHttp.
 */
class OpenAiEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        private readonly OpenAiHttp $http,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly int $timeoutSeconds,
        private readonly int $batchSize = 100,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function embed(array $texts): EmbeddingResult
    {
        foreach ($texts as $text) {
            if (! is_string($text) || trim($text) === '') {
                throw new InvalidArgumentException('Texts to embed must be non-empty strings.');
            }
        }

        if ($texts === []) {
            return new EmbeddingResult([], 0, $this->model);
        }

        $vectors = [];
        $tokens = 0;

        foreach (array_chunk($texts, $this->batchSize) as $batch) {
            $response = $this->http->post('/embeddings', [
                'model' => $this->model,
                'input' => $batch,
                'dimensions' => $this->dimensions, // supported by text-embedding-3 models
                'encoding_format' => 'float',
            ], $this->timeoutSeconds);

            array_push($vectors, ...$this->vectorsFrom($response, count($batch)));
            $tokens += (int) $response->json('usage.total_tokens', 0);
        }

        return new EmbeddingResult($vectors, $tokens, $this->model);
    }

    /**
     * @return list<list<float>>
     */
    private function vectorsFrom(Response $response, int $expectedCount): array
    {
        $items = $response->json('data');

        if (! is_array($items) || count($items) !== $expectedCount) {
            throw OpenAiException::invalidResponse("expected {$expectedCount} embeddings, got ".(is_array($items) ? count($items) : 'none'));
        }

        // OpenAI includes each input's position; never rely on the response order.
        usort($items, fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(function (array $item): array {
            $vector = $item['embedding'] ?? null;

            if (! is_array($vector) || count($vector) !== $this->dimensions) {
                throw OpenAiException::invalidResponse(
                    "expected {$this->dimensions} dimensions, got ".(is_array($vector) ? count($vector) : 'none'),
                );
            }

            return array_map(floatval(...), $vector);
        }, $items);
    }
}
