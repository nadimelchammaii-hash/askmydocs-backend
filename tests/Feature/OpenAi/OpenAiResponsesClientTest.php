<?php

use App\Services\OpenAi\JsonSchema;
use App\Services\OpenAi\OpenAiException;
use App\Services\OpenAi\OpenAiHttp;
use App\Services\OpenAi\OpenAiResponsesClient;
use App\Services\OpenAi\StructuredOutputClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const RESPONSES_TEST_KEY = 'sk-test-not-a-real-key-456';

function answerSchema(): JsonSchema
{
    return new JsonSchema('answer', [
        'type' => 'object',
        'properties' => [
            'answer_found' => ['type' => 'boolean'],
            'answer' => ['type' => 'string'],
        ],
        'required' => ['answer_found', 'answer'],
        'additionalProperties' => false,
    ]);
}

function responsesClient(?string $effort = 'low'): OpenAiResponsesClient
{
    return new OpenAiResponsesClient(
        http: new OpenAiHttp(RESPONSES_TEST_KEY, 'https://api.openai.test/v1'),
        model: 'gpt-6-luna',
        reasoningEffort: $effort,
        maxOutputTokens: 1000,
        timeoutSeconds: 60,
    );
}

/** A Responses API reply; `output` holds a reasoning item and the message, as real replies do. */
function responsesReply(array $content, string $status = 'completed', array $extra = []): array
{
    return [
        'id' => 'resp_123',
        'object' => 'response',
        'status' => $status,
        'model' => 'gpt-6-luna-2026-09-01',
        'output' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
            ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'content' => $content],
        ],
        'usage' => ['input_tokens' => 2900, 'output_tokens' => 310, 'total_tokens' => 3210,
            'output_tokens_details' => ['reasoning_tokens' => 120]],
        ...$extra,
    ];
}

function outputText(string $text): array
{
    return [['type' => 'output_text', 'text' => $text, 'annotations' => []]];
}

function responsesError(Closure $call): OpenAiException
{
    try {
        $call();
    } catch (OpenAiException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an OpenAiException.');
}

it('sends trusted instructions and the input separately, with a strict schema and no storage', function () {
    Http::fake(['*' => Http::response(responsesReply(outputText('{"answer_found":true,"answer":"Yes."}')))]);

    responsesClient()->generate('Follow these rules.', 'Question and sources.', answerSchema());

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.test/v1/responses'
        && $request->hasHeader('Authorization', 'Bearer '.RESPONSES_TEST_KEY)
        && $request->data() === [
            'model' => 'gpt-6-luna',
            'instructions' => 'Follow these rules.',
            'input' => 'Question and sources.',
            'store' => false,
            'max_output_tokens' => 1000,
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => 'answer',
                'schema' => answerSchema()->schema,
                'strict' => true,
            ]],
            'reasoning' => ['effort' => 'low'],
        ]);
});

it('omits the reasoning setting when none is configured', function () {
    Http::fake(['*' => Http::response(responsesReply(outputText('{"answer_found":false,"answer":""}')))]);

    responsesClient(effort: null)->generate('Rules.', 'Input.', answerSchema());

    Http::assertSent(fn (Request $request) => ! array_key_exists('reasoning', $request->data()));
});

it('returns the decoded JSON with model, token usage and duration', function () {
    Http::fake(['*' => Http::response(responsesReply(outputText('{"answer_found":true,"answer":"Within 30 days [S1]."}')))]);

    $output = responsesClient()->generate('Rules.', 'Input.', answerSchema());

    expect($output->data)->toBe(['answer_found' => true, 'answer' => 'Within 30 days [S1].'])
        ->and($output->model)->toBe('gpt-6-luna-2026-09-01') // the exact snapshot OpenAI used
        ->and($output->inputTokens)->toBe(2900)
        ->and($output->outputTokens)->toBe(310)
        ->and($output->durationMs)->toBeGreaterThanOrEqual(0);
});

it('joins text split across several output parts', function () {
    Http::fake(['*' => Http::response(responsesReply([
        ['type' => 'output_text', 'text' => '{"answer_found":true,'],
        ['type' => 'output_text', 'text' => '"answer":"Yes."}'],
    ]))]);

    expect(responsesClient()->generate('Rules.', 'Input.', answerSchema())->data['answer'])->toBe('Yes.');
});

it('turns unusable responses into permanent errors', function (array $reply, string $reason) {
    Http::fake(['*' => Http::response($reply)]);

    $error = responsesError(fn () => responsesClient()->generate('Rules.', 'Input.', answerSchema()));

    expect($error->reason)->toBe($reason)->and($error->isPermanent())->toBeTrue();
})->with([
    'refusal' => [responsesReply([['type' => 'refusal', 'refusal' => "I can't help with that."]]), OpenAiException::REFUSED],
    'content filter' => [responsesReply([], 'incomplete', ['incomplete_details' => ['reason' => 'content_filter']]), OpenAiException::REFUSED],
    'output limit reached' => [responsesReply(outputText('{"answer_found":true,"ans'), 'incomplete', ['incomplete_details' => ['reason' => 'max_output_tokens']]), OpenAiException::OUTPUT_TRUNCATED],
    'not JSON' => [responsesReply(outputText('Sure! The answer is yes.')), OpenAiException::INVALID_RESPONSE],
    'JSON list' => [responsesReply(outputText('[1,2]')), OpenAiException::INVALID_RESPONSE],
    'no message' => [['status' => 'completed', 'output' => [['type' => 'reasoning']]], OpenAiException::INVALID_RESPONSE],
]);

it('treats a failed response as a temporary provider problem', function () {
    Http::fake(['*' => Http::response(['status' => 'failed', 'error' => ['code' => 'server_error', 'message' => 'oops']])]);

    $error = responsesError(fn () => responsesClient()->generate('Rules.', 'Input.', answerSchema()));

    expect($error->reason)->toBe(OpenAiException::PROVIDER_ERROR)->and($error->isPermanent())->toBeFalse();
});

it('shares the HTTP error classification with embeddings', function (int $status, array $body, string $reason, bool $permanent) {
    Http::fake(['*' => Http::response($body, $status)]);

    $error = responsesError(fn () => responsesClient()->generate('Rules.', 'Input.', answerSchema()));

    expect($error->reason)->toBe($reason)->and($error->isPermanent())->toBe($permanent);
})->with([
    'invalid key' => [401, ['error' => ['code' => 'invalid_api_key']], OpenAiException::AUTHENTICATION_FAILED, true],
    'out of credit' => [429, ['error' => ['code' => 'insufficient_quota']], OpenAiException::QUOTA_EXCEEDED, true],
    'rate limited' => [429, ['error' => ['code' => 'rate_limit_exceeded']], OpenAiException::RATE_LIMITED, false],
    'bad schema' => [400, ['error' => ['type' => 'invalid_request_error', 'code' => 'invalid_json_schema']], OpenAiException::REQUEST_REJECTED, true],
    'overloaded' => [503, [], OpenAiException::PROVIDER_ERROR, false],
]);

it('never puts the key, the instructions or the input into errors', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-test-n***456']], 401)]);

    $error = responsesError(fn () => responsesClient()->generate('SECRET RULES', 'PRIVATE DOCUMENT TEXT', answerSchema()));

    foreach ([$error->getMessage(), (string) $error->detail] as $text) {
        expect($text)->not->toContain('sk-test')->not->toContain('SECRET RULES')->not->toContain('PRIVATE DOCUMENT');
    }
});

it('fails without calling OpenAI when no key is configured', function () {
    Http::fake();
    $client = new OpenAiResponsesClient(new OpenAiHttp(null, 'https://api.openai.test/v1'), 'gpt-6-luna', 'low', 1000, 60);

    expect(responsesError(fn () => $client->generate('Rules.', 'Input.', answerSchema()))->reason)
        ->toBe(OpenAiException::NOT_CONFIGURED);
    Http::assertNothingSent();
});

it('is the bound answer client, configured from config/services.php', function () {
    config(['services.openai.answer_model' => 'gpt-6-luna']);

    $client = app(StructuredOutputClient::class);

    expect($client)->toBeInstanceOf(OpenAiResponsesClient::class)
        ->and($client->model())->toBe('gpt-6-luna');
});

describe('JsonSchema', function () {
    it('accepts a strict schema with nested objects and arrays', function () {
        $schema = new JsonSchema('answer_with_citations', [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
                'citations' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['source' => ['type' => 'string'], 'quote' => ['type' => ['string', 'null']]],
                    'required' => ['source', 'quote'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['answer', 'citations'],
            'additionalProperties' => false,
        ]);

        expect($schema->name)->toBe('answer_with_citations');
    });

    it('rejects schemas that OpenAI strict mode would refuse', function (string $name, array $schema, string $message) {
        expect(fn () => new JsonSchema($name, $schema))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'root not an object' => ['answer', ['type' => 'array', 'items' => ['type' => 'string']], 'root'],
        'extra properties allowed' => ['answer', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']], 'additionalProperties'],
        'optional property' => ['answer', ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], 'required' => ['a'], 'additionalProperties' => false], 'required'],
        'nested object not strict' => ['answer', ['type' => 'object', 'properties' => ['list' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']], 'required' => ['x']]]], 'required' => ['list'], 'additionalProperties' => false], '$.list[]'],
        'invalid name' => ['answer schema!', ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false], 'name'],
    ]);
});
