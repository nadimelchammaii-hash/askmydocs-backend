<?php

use App\Enums\DocumentStatus;
use App\Jobs\EmbedDocumentChunks;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentPage;
use App\Models\User;
use App\Services\Chunking\TextChunker;
use App\Services\Embeddings\EmbeddingClient;
use App\Services\Embeddings\EmbeddingException;
use App\Services\Embeddings\EmbeddingResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEmbeddingClient;
use Tests\Support\FakePdfTextExtractor;
use Tests\Support\PdfFixture;

beforeEach(function () {
    $this->embeddings = FakeEmbeddingClient::bind();
});

/** A document whose text was extracted and now waits for embedding. */
function documentAwaitingEmbedding(array $pages = ['Annual report 2025. Revenue grew by 12 percent.', 'The refund policy allows returns within 30 days.'], array $attributes = []): Document
{
    $document = Document::factory()->create([
        'title' => 'Annual Report',
        'status' => DocumentStatus::Embedding,
        'page_count' => count($pages),
        ...$attributes,
    ]);

    foreach ($pages as $index => $text) {
        DocumentPage::factory()->for($document)->create([
            'page_number' => $index + 1, 'text' => $text, 'char_count' => mb_strlen($text),
        ]);
    }

    return $document;
}

function runEmbedJob(Document $document): EmbedDocumentChunks
{
    $job = (new EmbedDocumentChunks($document->fresh()))->withFakeQueueInteractions();
    $job->handle(app(TextChunker::class), app(EmbeddingClient::class));

    return $job;
}

it('runs on the documents queue with retries, backoff and a timeout', function () {
    $job = new EmbedDocumentChunks(Document::factory()->create());

    expect($job->queue)->toBe('documents')
        ->and($job->tries)->toBe(4)
        ->and($job->backoff)->toBe([30, 120, 300])
        ->and($job->timeout)->toBe(240)
        ->and($job->deleteWhenMissingModels)->toBeTrue()
        ->and($job->uniqueId())->toBe($job->document->id)
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout)
        ->and(config('services.openai.timeout'))->toBeLessThan($job->timeout);
});

it('stores one embedded chunk per piece of text and marks the document ready', function () {
    $document = documentAwaitingEmbedding();

    EmbedDocumentChunks::dispatch($document);

    $document->refresh();
    $chunks = $document->chunks;

    expect($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->embedding_model)->toBe('fake-embedding-model')
        ->and($document->processed_at)->not->toBeNull()
        ->and($chunks->map(fn (DocumentChunk $c) => [$c->chunk_index, $c->page_number, $c->content, $c->char_count])->all())->toBe([
            [0, 1, 'Annual report 2025. Revenue grew by 12 percent.', 47],
            [1, 2, 'The refund policy allows returns within 30 days.', 48],
        ])
        ->and($chunks[0]->embedding)->toHaveCount(1536);
});

it('embeds each chunk with a header naming the document and page, but stores only the text', function () {
    $document = documentAwaitingEmbedding();

    runEmbedJob($document);

    expect($this->embeddings->calls)->toBe([[
        "Document: Annual Report\nPage 1\n\nAnnual report 2025. Revenue grew by 12 percent.",
        "Document: Annual Report\nPage 2\n\nThe refund policy allows returns within 30 days.",
    ]])->and($document->chunks()->first()->content)->toBe('Annual report 2025. Revenue grew by 12 percent.');
});

it('stores the vector returned for each chunk', function () {
    $this->embeddings->vectorFor('refund policy', [1, 0, 0]);
    $document = documentAwaitingEmbedding();

    runEmbedJob($document);

    $vector = DocumentChunk::where('page_number', 2)->sole()->embedding;
    expect(array_slice($vector, 0, 3))->toBe([1.0, 0.0, 0.0]);
});

it('replaces chunks from an earlier attempt', function () {
    $document = documentAwaitingEmbedding();
    DocumentChunk::factory()->for($document)->count(5)->sequence(fn ($s) => ['chunk_index' => $s->index + 10])->create();

    runEmbedJob($document);

    expect($document->chunks()->pluck('chunk_index')->all())->toBe([0, 1]);
});

it('does nothing unless the document is waiting for embedding', function (DocumentStatus $status) {
    $document = documentAwaitingEmbedding(attributes: ['status' => $status]);

    runEmbedJob($document);

    expect($this->embeddings->calls)->toBe([])
        ->and(DocumentChunk::count())->toBe(0)
        ->and($document->refresh()->status)->toBe($status);
})->with([DocumentStatus::Queued, DocumentStatus::Extracting, DocumentStatus::Ready, DocumentStatus::Failed]);

it('fails at once when the configuration is the problem', function (EmbeddingException $exception) {
    $this->embeddings->failWith($exception);

    $job = runEmbedJob(documentAwaitingEmbedding());

    $job->assertFailedWith(EmbeddingException::class);
    $job->assertNotReleased();
    expect(DocumentChunk::count())->toBe(0);
})->with([
    'no API key' => fn () => EmbeddingException::notConfigured(),
    'invalid key' => fn () => EmbeddingException::authenticationFailed('HTTP 401'),
    'no credit' => fn () => EmbeddingException::quotaExceeded('HTTP 429 code=insufficient_quota'),
]);

it('shows users a generic message when embedding fails', function () {
    $this->embeddings->failWith(EmbeddingException::authenticationFailed('HTTP 401'));
    $document = documentAwaitingEmbedding();

    EmbedDocumentChunks::dispatch($document); // sync queue: fail() calls failed()

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->error_code)->toBe('embedding_failed')
        ->and($document->error_message)->toBe(EmbedDocumentChunks::ERROR_MESSAGE)
        ->and($document->error_message)->not->toContain('key');
});

it('rethrows temporary failures so the queue retries after the backoff', function () {
    $this->embeddings->failWith(EmbeddingException::providerError('HTTP 503'));
    $document = documentAwaitingEmbedding();
    $job = (new EmbedDocumentChunks($document))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(TextChunker::class), app(EmbeddingClient::class)))
        ->toThrow(EmbeddingException::class);

    $job->assertNotFailed();
    expect($document->refresh()->status)->toBe(DocumentStatus::Embedding);
});

it('waits as long as OpenAI asks when rate limited', function () {
    $this->embeddings->failWith(EmbeddingException::rateLimited('HTTP 429', retryAfter: 20));

    $job = runEmbedJob(documentAwaitingEmbedding());

    $job->assertReleased(delay: 20);
    $job->assertNotFailed();
});

it('marks the document failed after the last attempt, but never a ready one', function () {
    $embedding = documentAwaitingEmbedding();
    $ready = documentAwaitingEmbedding(attributes: ['status' => DocumentStatus::Ready]);

    (new EmbedDocumentChunks($embedding))->failed(new RuntimeException('SQLSTATE connection lost'));
    (new EmbedDocumentChunks($ready))->failed(new RuntimeException('late failure'));

    expect($embedding->refresh()->status)->toBe(DocumentStatus::Failed)
        ->and($embedding->error_code)->toBe('embedding_failed')
        ->and($ready->refresh()->status)->toBe(DocumentStatus::Ready);
});

it('stores nothing when the document is deleted while OpenAI is answering', function () {
    $document = documentAwaitingEmbedding();
    app()->instance(EmbeddingClient::class, new class($document) implements EmbeddingClient
    {
        public function __construct(private Document $document) {}

        public function embed(array $texts): EmbeddingResult
        {
            $this->document->delete(); // the user deletes it meanwhile

            return new EmbeddingResult(array_fill(0, count($texts), array_fill(0, 1536, 0.1)), 10, 'fake');
        }

        public function model(): string
        {
            return 'fake';
        }
    });

    runEmbedJob($document);

    expect(Document::count())->toBe(0)->and(DocumentChunk::count())->toBe(0);
});

it('stores nothing when the document changed state while OpenAI was answering', function () {
    // E.g. this attempt hung, was marked failed, and the user already retried.
    $document = documentAwaitingEmbedding();
    app()->instance(EmbeddingClient::class, new class($document) implements EmbeddingClient
    {
        public function __construct(private Document $document) {}

        public function embed(array $texts): EmbeddingResult
        {
            Document::whereKey($this->document->id)->update(['status' => DocumentStatus::Queued]);

            return new EmbeddingResult(array_fill(0, count($texts), array_fill(0, 1536, 0.1)), 10, 'fake');
        }

        public function model(): string
        {
            return 'fake';
        }
    });

    runEmbedJob($document);

    expect(DocumentChunk::count())->toBe(0)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Queued);
});

it('logs the token usage but never the text', function () {
    Log::spy();
    $document = documentAwaitingEmbedding();

    runEmbedJob($document);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'Document embedded'
        && $context['chunks'] === 2
        && $context['tokens'] > 0
        && ! str_contains(json_encode($context), 'refund'));
});

it('lets the user retry a document whose embedding failed', function () {
    Storage::fake(config('documents.disk'));
    FakePdfTextExtractor::bind(['Annual report 2025. Revenue grew by 12 percent.']);
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->failed(EmbedDocumentChunks::ERROR_CODE, EmbedDocumentChunks::ERROR_MESSAGE)->create();
    Storage::disk(config('documents.disk'))->put($document->storage_path, PdfFixture::bytes(['x']));

    // Sync queue: the retry runs extraction, then embedding, immediately.
    $this->fromSpa()->actingAs($user)->postJson("/api/v1/documents/{$document->id}/retry")->assertAccepted();

    expect($document->refresh()->status)->toBe(DocumentStatus::Ready)
        ->and($document->chunks()->count())->toBe(1);
});
