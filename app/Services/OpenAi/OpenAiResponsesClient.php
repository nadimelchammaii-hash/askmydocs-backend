<?php

namespace App\Services\OpenAi;

use Illuminate\Http\Client\Response;
use JsonException;

/**
 * Calls OpenAI's Responses API (POST /v1/responses) with Structured Outputs:
 * the model must reply with JSON matching a strict JSON Schema.
 *
 * - `instructions` carries our trusted rules; `input` carries the request and
 *   any untrusted document text (separation against prompt injection).
 * - `store: false`: OpenAI would otherwise keep responses for at least 30 days.
 * - No tools are offered, so the model can only return text in our schema.
 * - A response is only accepted if it is complete and is valid JSON; refusals,
 *   content filtering and truncation become permanent OpenAiExceptions.
 */
class OpenAiResponsesClient implements StructuredOutputClient
{
    public function __construct(
        private readonly OpenAiHttp $http,
        private readonly string $model,
        private readonly ?string $reasoningEffort,
        private readonly int $maxOutputTokens,
        private readonly int $timeoutSeconds,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function generate(string $instructions, string $input, JsonSchema $schema): StructuredOutput
    {
        $payload = [
            'model' => $this->model,
            'instructions' => $instructions,
            'input' => $input,
            'store' => false,
            'max_output_tokens' => $this->maxOutputTokens,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schema->name,
                    'schema' => $schema->schema,
                    'strict' => true,
                ],
            ],
        ];

        if (filled($this->reasoningEffort)) {
            $payload['reasoning'] = ['effort' => $this->reasoningEffort];
        }

        $started = hrtime(true);
        $response = $this->http->post('/responses', $payload, $this->timeoutSeconds);
        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

        return new StructuredOutput(
            data: $this->decode($response),
            model: (string) ($response->json('model') ?? $this->model),
            inputTokens: (int) $response->json('usage.input_tokens', 0),
            outputTokens: (int) $response->json('usage.output_tokens', 0),
            durationMs: $durationMs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $status = $response->json('status');

        if ($status === 'incomplete') {
            $reason = (string) $response->json('incomplete_details.reason', 'unknown');

            throw $reason === 'content_filter'
                ? OpenAiException::refused('incomplete: content_filter')
                : OpenAiException::outputTruncated("incomplete: {$reason}");
        }

        if ($status !== 'completed') {
            // e.g. "failed", with an error object: treat as a provider problem.
            throw OpenAiException::providerError('response status '.json_encode($status).' code='.json_encode($response->json('error.code')));
        }

        // Never assume output[0].content[0]: collect the message parts.
        $text = '';
        foreach ((array) $response->json('output', []) as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue; // e.g. reasoning items
            }

            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'refusal') {
                    throw OpenAiException::refused('model refusal');
                }

                if (($part['type'] ?? null) === 'output_text') {
                    $text .= $part['text'] ?? '';
                }
            }
        }

        if ($text === '') {
            throw OpenAiException::invalidResponse('no output text');
        }

        try {
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw OpenAiException::invalidResponse('output is not valid JSON');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw OpenAiException::invalidResponse('output is not a JSON object');
        }

        return $data;
    }
}
