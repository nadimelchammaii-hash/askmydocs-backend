<?php

namespace App\Services\OpenAi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one place that talks HTTP to OpenAI: authentication, timeouts and turning
 * every failure into an OpenAiException with a permanent/transient verdict.
 *
 * It does not retry: queued jobs own the retry policy. It logs nothing, and
 * errors carry only status codes and OpenAI's error type/code, never the API
 * key, the request body or OpenAI's error message (which can echo key parts).
 */
class OpenAiHttp
{
    public function __construct(
        #[\SensitiveParameter] private readonly ?string $apiKey,
        private readonly string $baseUrl,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws OpenAiException
     */
    public function post(string $path, array $payload, int $timeoutSeconds): Response
    {
        if (blank($this->apiKey)) {
            throw OpenAiException::notConfigured();
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout($timeoutSeconds)
                ->post($path, $payload);
        } catch (ConnectionException $exception) {
            throw OpenAiException::connectionFailed($exception->getMessage());
        }

        if ($response->failed()) {
            throw $this->errorFor($response);
        }

        return $response;
    }

    private function errorFor(Response $response): OpenAiException
    {
        $status = $response->status();
        $type = $response->json('error.type');
        $code = $response->json('error.code');
        $detail = "HTTP {$status}".($type ? " type={$type}" : '').($code ? " code={$code}" : '');

        return match (true) {
            $status === 401, $status === 403 => OpenAiException::authenticationFailed($detail),
            // A 429 means either "slow down" (retry) or "out of credit" (retrying cannot help).
            $status === 429 && $code === 'insufficient_quota' => OpenAiException::quotaExceeded($detail),
            $status === 429 => OpenAiException::rateLimited($detail, $this->retryAfter($response)),
            $status >= 500 => OpenAiException::providerError($detail),
            default => OpenAiException::requestRejected($detail),
        };
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return is_numeric($value) ? max(1, (int) ceil((float) $value)) : null;
    }
}
