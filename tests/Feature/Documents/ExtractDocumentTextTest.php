<?php

use App\Enums\DocumentStatus;
use App\Jobs\ExtractDocumentText;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\User;
use App\Services\Pdf\PdfExtractionException;
use App\Services\Pdf\PdfTextExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePdfTextExtractor;
use Tests\Support\PdfFixture;

beforeEach(function () {
    Storage::fake(config('documents.disk'));
});

afterEach(fn () => PdfFixture::cleanUp());

/** A document in the given state whose file exists on the (fake) disk. */
function storedDocument(array $attributes = []): Document
{
    $document = Document::factory()->create(['page_count' => 2, ...$attributes]);
    Storage::disk(config('documents.disk'))->put($document->storage_path, PdfFixture::bytes(['Some text.', 'More text.']));

    return $document;
}

/** @return list<array{int, string, int}> [page_number, text, char_count] ordered by page */
function storedPages(Document $document): array
{
    return DocumentPage::where('document_id', $document->id)->orderBy('page_number')->get()
        ->map(fn (DocumentPage $page) => [$page->page_number, $page->text, $page->char_count])
        ->all();
}

it('runs on the documents queue with retries, backoff and a timeout', function () {
    $job = new ExtractDocumentText(Document::factory()->create());

    expect($job->queue)->toBe('documents')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60])
        ->and($job->timeout)->toBe(120)
        ->and($job->deleteWhenMissingModels)->toBeTrue()
        ->and($job->uniqueId())->toBe($job->document->id)
        // Timing chain: Poppler timeout < job timeout < queue retry_after.
        ->and(config('documents.process_timeout'))->toBeLessThan($job->timeout)
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout);
});

it('stores every page and marks the document ready', function () {
    FakePdfTextExtractor::bind(['First page text.', '', 'Último página, ünïcödé.']);
    $document = storedDocument();

    ExtractDocumentText::dispatch($document);

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->page_count)->toBe(3) // pdftotext is the source of truth
        ->and($document->processed_at)->not->toBeNull()
        ->and($document->processing_started_at)->not->toBeNull()
        ->and(storedPages($document))->toBe([
            [1, 'First page text.', 16],
            [2, '', 0],
            [3, 'Último página, ünïcödé.', 23],
        ]);
});

it('replaces pages from an earlier, interrupted attempt', function () {
    FakePdfTextExtractor::bind(['New first page.', 'New second page.']);
    $document = storedDocument(['status' => DocumentStatus::Extracting]);
    DocumentPage::factory()->for($document)->count(3)->sequence(
        ['page_number' => 1], ['page_number' => 2], ['page_number' => 3],
    )->create();

    ExtractDocumentText::dispatch($document);

    expect(storedPages($document))->toBe([[1, 'New first page.', 15], [2, 'New second page.', 16]])
        ->and($document->refresh()->status)->toBe(DocumentStatus::Ready);
});

it('leaves finished documents alone', function (DocumentStatus $status) {
    $extractor = FakePdfTextExtractor::bind();
    $document = storedDocument(['status' => $status]);

    ExtractDocumentText::dispatch($document);

    expect($extractor->extractCalls)->toBe(0)
        ->and($document->refresh()->status)->toBe($status);
})->with([DocumentStatus::Ready, DocumentStatus::Failed]);

it('fails at once, without retrying, when the PDF itself is the problem', function () {
    FakePdfTextExtractor::bind(PdfExtractionException::noTextLayer());
    $document = storedDocument();
    $job = (new ExtractDocumentText($document))->withFakeQueueInteractions();

    $job->handle(app(PdfTextExtractor::class));

    $job->assertFailedWith(PdfExtractionException::class);
    $job->assertNotReleased();
});

it('records the reason when a permanent failure fails the job', function () {
    FakePdfTextExtractor::bind(PdfExtractionException::noTextLayer());
    $document = storedDocument();

    ExtractDocumentText::dispatch($document); // sync queue: fail() calls failed()

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->error_code)->toBe('no_text_layer')
        ->and($document->error_message)->toBe(PdfExtractionException::noTextLayer()->getMessage())
        ->and(storedPages($document))->toBe([]);
});

it('rethrows transient failures so the queue retries them', function () {
    FakePdfTextExtractor::bind(PdfExtractionException::timedOut());
    $document = storedDocument();
    $job = (new ExtractDocumentText($document))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(PdfTextExtractor::class)))
        ->toThrow(PdfExtractionException::class);

    $job->assertNotFailed();
    expect($document->refresh()->status)->toBe(DocumentStatus::Extracting);
});

it('marks the document failed after the last attempt', function (?Throwable $exception, string $code, string $message) {
    $document = storedDocument(['status' => DocumentStatus::Extracting]);

    (new ExtractDocumentText($document))->failed($exception);

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->error_code->toBe($code)
        ->error_message->toBe($message);
})->with([
    'timeout' => [fn () => PdfExtractionException::timedOut(), 'extraction_timeout', 'Reading this PDF took too long.'],
    // e.g. a database error or a killed worker: internals never reach the user.
    'unexpected error' => [fn () => new RuntimeException('SQLSTATE[08006] connection lost'), 'extraction_failed', 'This PDF could not be processed.'],
    'no exception' => [null, 'extraction_failed', 'This PDF could not be processed.'],
]);

it('never marks a ready document as failed', function () {
    $document = storedDocument(['status' => DocumentStatus::Ready]);

    (new ExtractDocumentText($document))->failed(new RuntimeException('late failure'));

    expect($document->refresh()->status)->toBe(DocumentStatus::Ready);
});

it('fails the document when its file is missing', function () {
    $extractor = FakePdfTextExtractor::bind();
    $document = Document::factory()->create();

    ExtractDocumentText::dispatch($document);

    expect($extractor->extractCalls)->toBe(0)
        ->and($document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->error_code->toBe('file_missing');
});

it('writes nothing when the document is deleted during extraction', function () {
    $document = storedDocument();
    FakePdfTextExtractor::bind(function () use ($document) {
        Document::whereKey($document->id)->delete(); // the user deletes it meanwhile

        return ['Text of a deleted document.'];
    });

    ExtractDocumentText::dispatch($document);

    expect(Document::count())->toBe(0)->and(DocumentPage::count())->toBe(0);
});

describe('on the real database queue', function () {
    beforeEach(fn () => config(['queue.default' => 'database']));

    function workDocumentsQueue(): void
    {
        test()->artisan('queue:work', ['--once' => true, '--queue' => 'documents'])->assertSuccessful();
    }

    it('queues each document only once at a time', function () {
        $document = storedDocument();

        ExtractDocumentText::dispatch($document);
        ExtractDocumentText::dispatch($document);

        expect(DB::table('jobs')->where('queue', 'documents')->count())->toBe(1);
    });

    it('silently drops the job when the document was deleted before it ran', function () {
        $extractor = FakePdfTextExtractor::bind();
        $document = storedDocument();
        ExtractDocumentText::dispatch($document);
        $document->delete();

        workDocumentsQueue();

        expect($extractor->extractCalls)->toBe(0)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('takes an upload all the way to ready with real Poppler', function () {
        $user = User::factory()->create();
        $upload = PdfFixture::upload('report.pdf', PdfFixture::bytes([
            'Annual report 2025. Revenue grew by 12 percent.',
            'The refund policy allows returns within 30 days.',
        ]));

        $id = $this->fromSpa()->actingAs($user)
            ->post('/api/v1/documents', ['file' => $upload])
            ->assertAccepted()
            ->json('data.id');

        workDocumentsQueue();

        $this->fromSpa()->actingAs($user)->getJson("/api/v1/documents/{$id}")
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.is_finished', true)
            ->assertJsonPath('data.page_count', 2);

        $this->fromSpa()->actingAs($user)->getJson("/api/v1/documents/{$id}/pages/2")
            ->assertOk()
            ->assertJsonPath('data.text', 'The refund policy allows returns within 30 days.');
    })->skip(fn () => ! popplerAvailable(), 'Poppler is not installed; see README.');
});
