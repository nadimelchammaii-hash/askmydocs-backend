<?php

use App\Services\Embeddings\EmbeddingClient;
use App\Services\Embeddings\OpenAiEmbeddingClient;
use App\Services\OpenAi\OpenAiException;
use App\Services\OpenAi\OpenAiHttp;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

const TEST_API_KEY = 'sk-test-not-a-real-key-123';

/** Small vectors keep the fakes readable; the size check works the same at 1536. */
function embeddingClient(?string $apiKey = TEST_API_KEY, int $batchSize = 100): OpenAiEmbeddingClient
{
    return new OpenAiEmbeddingClient(
        http: new OpenAiHttp($apiKey, 'https://api.openai.test/v1'),
        model: 'text-embedding-3-small',
        dimensions: 3,
        timeoutSeconds: 30,
        batchSize: $batchSize,
    );
}

/** A successful OpenAI response for the given inputs, optionally in shuffled order. */
function embeddingsResponse(array $inputs, bool $shuffled = false, int $dimensions = 3): array
{
    $data = array_map(fn (int $i) => [
        'object' => 'embedding',
        'index' => $i,
        'embedding' => array_fill(0, $dimensions, $i / 10),
    ], array_keys($inputs));

    return [
        'object' => 'list',
        'data' => $shuffled ? array_reverse($data) : $data,
        'model' => 'text-embedding-3-small',
        'usage' => ['prompt_tokens' => 7 * count($inputs), 'total_tokens' => 7 * count($inputs)],
    ];
}

function embeddingError(EmbeddingClient $client): OpenAiException
{
    try {
        $client->embed(['Some chunk text.']);
    } catch (OpenAiException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an OpenAiException.');
}

it('sends the documented request and returns one vector per text', function () {
    Http::fake(['*' => fn (Request $request) => Http::response(embeddingsResponse($request['input']))]);

    $result = embeddingClient()->embed(['First chunk.', 'Second chunk.']);

    expect($result->vectors)->toBe([[0.0, 0.0, 0.0], [0.1, 0.1, 0.1]])
        ->and($result->totalTokens)->toBe(14)
        ->and($result->model)->toBe('text-embedding-3-small');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.test/v1/embeddings'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer '.TEST_API_KEY)
        && $request->data() === [
            'model' => 'text-embedding-3-small',
            'input' => ['First chunk.', 'Second chunk.'],
            'dimensions' => 3,
            'encoding_format' => 'float',
        ]);
});

it('sends large inputs in batches and keeps the order', function () {
    Http::fake(['*' => fn (Request $request) => Http::response(embeddingsResponse($request['input']))]);
    $texts = array_map(fn (int $i) => "Chunk {$i}.", range(1, 250));

    $result = embeddingClient(batchSize: 100)->embed($texts);

    Http::assertSentCount(3);
    $sizes = Http::recorded()->map(fn (array $pair) => count($pair[0]['input']))->all();
    expect($sizes)->toBe([100, 100, 50])
        ->and($result->vectors)->toHaveCount(250)
        ->and($result->vectors[100])->toBe([0.0, 0.0, 0.0]) // first of the 2nd batch
        ->and($result->totalTokens)->toBe(250 * 7);
});

it('restores input order using the index of each embedding', function () {
    Http::fake(['*' => fn (Request $request) => Http::response(embeddingsResponse($request['input'], shuffled: true))]);

    $vectors = embeddingClient()->embed(['a', 'b', 'c'])->vectors;

    expect($vectors)->toBe([[0.0, 0.0, 0.0], [0.1, 0.1, 0.1], [0.2, 0.2, 0.2]]);
});

it('makes no request for an empty list', function () {
    Http::fake();

    expect(embeddingClient()->embed([])->vectors)->toBe([]);
    Http::assertNothingSent();
});

it('refuses empty texts, which OpenAI would reject', function () {
    Http::fake();

    embeddingClient()->embed(['Fine.', '   ']);
})->throws(InvalidArgumentException::class);

it('fails without calling OpenAI when no API key is configured', function (?string $key) {
    Http::fake();

    expect(embeddingError(embeddingClient(apiKey: $key)))
        ->reason->toBe(OpenAiException::NOT_CONFIGURED)
        ->isPermanent()->toBeTrue();
    Http::assertNothingSent();
})->with([null, '']);

it('classifies OpenAI errors as permanent or retryable', function (int $status, array $body, string $reason, bool $permanent) {
    Http::fake(['*' => Http::response($body, $status)]);

    expect(embeddingError(embeddingClient()))
        ->reason->toBe($reason)
        ->isPermanent()->toBe($permanent);
})->with([
    'invalid key' => [401, ['error' => ['type' => 'invalid_request_error', 'code' => 'invalid_api_key']], OpenAiException::AUTHENTICATION_FAILED, true],
    'no access' => [403, ['error' => ['type' => 'invalid_request_error']], OpenAiException::AUTHENTICATION_FAILED, true],
    'out of credit' => [429, ['error' => ['type' => 'insufficient_quota', 'code' => 'insufficient_quota']], OpenAiException::QUOTA_EXCEEDED, true],
    'rate limited' => [429, ['error' => ['type' => 'requests', 'code' => 'rate_limit_exceeded']], OpenAiException::RATE_LIMITED, false],
    'input too long' => [400, ['error' => ['type' => 'invalid_request_error']], OpenAiException::REQUEST_REJECTED, true],
    'unknown model' => [404, ['error' => ['type' => 'invalid_request_error', 'code' => 'model_not_found']], OpenAiException::REQUEST_REJECTED, true],
    'server error' => [500, ['error' => ['type' => 'server_error']], OpenAiException::PROVIDER_ERROR, false],
    'overloaded' => [503, [], OpenAiException::PROVIDER_ERROR, false],
]);

it('passes on how long OpenAI asks us to wait', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '2.5'])]);

    expect(embeddingError(embeddingClient())->retryAfter)->toBe(3);
});

it('treats network failures as retryable', function () {
    Http::fake(['*' => Http::failedConnection('Connection timed out')]);

    expect(embeddingError(embeddingClient()))
        ->reason->toBe(OpenAiException::CONNECTION_FAILED)
        ->isPermanent()->toBeFalse();
});

it('rejects responses that do not match the request', function (array $body) {
    Http::fake(['*' => Http::response($body)]);

    expect(embeddingError(embeddingClient())->reason)->toBe(OpenAiException::INVALID_RESPONSE);
})->with([
    'wrong vector size' => [embeddingsResponse(['x'], dimensions: 5)],
    'missing embeddings' => [['data' => [], 'usage' => ['total_tokens' => 1]]],
    'not JSON data' => [['unexpected' => true]],
]);

it('never exposes the API key or the text in errors', function (int $status) {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-test-n***123']], $status)]);

    $error = embeddingError(embeddingClient());

    foreach ([$error->getMessage(), (string) $error->detail] as $text) {
        expect($text)->not->toContain('sk-test')->not->toContain('Some chunk text');
    }
})->with([401, 400, 500]);

it('is the bound embedding client, configured from config/services.php', function () {
    config(['services.openai.api_key' => TEST_API_KEY, 'services.openai.embedding_model' => 'text-embedding-3-small']);

    $client = app(EmbeddingClient::class);

    expect($client)->toBeInstanceOf(OpenAiEmbeddingClient::class)
        ->and($client->model())->toBe('text-embedding-3-small');
});

it('blocks any real HTTP request in tests', function () {
    // No Http::fake() here: the request must be stopped before it leaves the machine.
    embeddingClient()->embed(['This must never reach OpenAI.']);
})->throws(StrayRequestException::class);
