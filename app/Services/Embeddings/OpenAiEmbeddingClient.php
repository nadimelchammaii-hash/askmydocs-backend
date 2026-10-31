<?php

namespace App\Services\Embeddings;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Calls OpenAI's embeddings endpoint (POST /v1/embeddings) with Laravel's HTTP client.
 *
 * - Sends texts in batches (OpenAI allows up to 2,048 inputs and 300,000 tokens
 *   per request; 100 chunks of about 400 tokens stay far below both).
 * - Does not retry by itself: the queued job decides about retries and waiting,
 *   so there is a single place for that policy.
 * - Checks every response: one vector per input, of the configured size.
 * - Logs nothing; errors carry only status codes and OpenAI's error type/code,
 *   never the API key or the texts.
 */
class OpenAiEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        #[\SensitiveParameter] private readonly ?string $apiKey,
        private readonly string $baseUrl,
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

        if (blank($this->apiKey)) {
            throw EmbeddingException::notConfigured();
        }

        $vectors = [];
        $tokens = 0;

        foreach (array_chunk($texts, $this->batchSize) as $batch) {
            [$batchVectors, $batchTokens] = $this->embedBatch($batch);
            array_push($vectors, ...$batchVectors);
            $tokens += $batchTokens;
        }

        return new EmbeddingResult($vectors, $tokens, $this->model);
    }

    /**
     * @param  list<string>  $batch
     * @return array{list<list<float>>, int}
     */
    private function embedBatch(array $batch): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout($this->timeoutSeconds)
                ->post('/embeddings', [
                    'model' => $this->model,
                    'input' => $batch,
                    'dimensions' => $this->dimensions, // supported by text-embedding-3 models
                    'encoding_format' => 'float',
                ]);
        } catch (ConnectionException $exception) {
            throw EmbeddingException::connectionFailed($exception->getMessage());
        }

        if ($response->failed()) {
            throw $this->errorFor($response);
        }

        return [$this->vectorsFrom($response, count($batch)), (int) $response->json('usage.total_tokens', 0)];
    }

    /**
     * @return list<list<float>>
     */
    private function vectorsFrom(Response $response, int $expectedCount): array
    {
        $items = $response->json('data');

        if (! is_array($items) || count($items) !== $expectedCount) {
            throw EmbeddingException::invalidResponse("expected {$expectedCount} embeddings, got ".(is_array($items) ? count($items) : 'none'));
        }

        // OpenAI includes each input's position; never rely on the response order.
        usort($items, fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(function (array $item): array {
            $vector = $item['embedding'] ?? null;

            if (! is_array($vector) || count($vector) !== $this->dimensions) {
                throw EmbeddingException::invalidResponse(
                    "expected {$this->dimensions} dimensions, got ".(is_array($vector) ? count($vector) : 'none'),
                );
            }

            return array_map(floatval(...), $vector);
        }, $items);
    }

    private function errorFor(Response $response): EmbeddingException
    {
        $status = $response->status();
        $type = $response->json('error.type');
        $code = $response->json('error.code');
        $detail = "HTTP {$status}".($type ? " type={$type}" : '').($code ? " code={$code}" : '');

        return match (true) {
            $status === 401, $status === 403 => EmbeddingException::authenticationFailed($detail),
            // A 429 means either "slow down" (retry) or "out of credit" (retrying cannot help).
            $status === 429 && $code === 'insufficient_quota' => EmbeddingException::quotaExceeded($detail),
            $status === 429 => EmbeddingException::rateLimited($detail, $this->retryAfter($response)),
            $status >= 500 => EmbeddingException::providerError($detail),
            default => EmbeddingException::requestRejected($detail),
        };
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return is_numeric($value) ? max(1, (int) ceil((float) $value)) : null;
    }
}
