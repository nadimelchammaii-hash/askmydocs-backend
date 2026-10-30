<?php

use App\Enums\DocumentStatus;
use App\Jobs\ExtractDocumentText;
use App\Models\Document;
use App\Models\User;
use App\Services\Pdf\PdfExtractionException;
use App\Services\Pdf\PdfInfo;
use App\Services\Pdf\PdfTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixture;

beforeEach(function () {
    Storage::fake(config('documents.disk'));
    Queue::fake();
    $this->user = User::factory()->create();
});

afterEach(fn () => PdfFixture::cleanUp());

/** Replaces Poppler: inspect() returns the given page count or throws the given exception. */
function fakeInspect(int|PdfExtractionException $result): void
{
    app()->instance(PdfTextExtractor::class, new class($result) implements PdfTextExtractor
    {
        public function __construct(private int|PdfExtractionException $result) {}

        public function inspect(string $path): PdfInfo
        {
            return is_int($this->result) ? new PdfInfo($this->result) : throw $this->result;
        }

        public function extractPages(string $path): array
        {
            throw new LogicException('Uploads must not extract text.');
        }
    });
}

/** Poppler must not run at all, e.g. for files that fail the cheap checks. */
function forbidInspect(): void
{
    app()->instance(PdfTextExtractor::class, Mockery::mock(PdfTextExtractor::class)->shouldNotReceive('inspect')->getMock());
}

function pdfUpload(string $name = 'Annual Report 2025.pdf'): UploadedFile
{
    return PdfFixture::upload($name, PdfFixture::bytes(['Annual report text on page one.']));
}

/** @return list<string> */
function storedFiles(): array
{
    return Storage::disk(config('documents.disk'))->allFiles();
}

it('stores the file and creates a queued document', function () {
    fakeInspect(12);
    $upload = pdfUpload();
    $sha256 = hash_file('sha256', $upload->getRealPath());

    $response = $this->fromSpa()->actingAs($this->user)
        ->post('/api/v1/documents', ['file' => $upload]);

    $response->assertAccepted()
        ->assertJsonPath('data.title', 'Annual Report 2025')
        ->assertJsonPath('data.original_filename', 'Annual Report 2025.pdf')
        ->assertJsonPath('data.page_count', 12)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.is_finished', false)
        ->assertJsonMissingPath('data.storage_path')
        ->assertJsonMissingPath('data.sha256')
        ->assertJsonMissingPath('data.user_id');

    $document = Document::sole();

    expect($document->id)->toBe($response->json('data.id'))
        ->and($document->id)->toBe(strtolower($document->id)) // same ULID format as model-generated IDs
        ->and($document->user_id)->toBe($this->user->id)
        ->and($document->status)->toBe(DocumentStatus::Queued)
        ->and($document->storage_path)->toBe("{$this->user->id}/{$document->id}.pdf")
        ->and($document->size_bytes)->toBe(strlen(PdfFixture::bytes(['Annual report text on page one.'])))
        ->and($document->sha256)->toBe($sha256)
        ->and(storedFiles())->toBe([$document->storage_path]);

    Queue::assertPushedOn('documents', ExtractDocumentText::class, fn (ExtractDocumentText $job) => $job->document->is($document));
});

it('uses the given title', function () {
    fakeInspect(1);

    $this->fromSpa()->actingAs($this->user)
        ->post('/api/v1/documents', ['file' => pdfUpload(), 'title' => '  Q4 numbers  '])
        ->assertAccepted()
        ->assertJsonPath('data.title', 'Q4 numbers');
});

it('never uses the client filename in the storage path', function () {
    fakeInspect(1);

    $this->fromSpa()->actingAs($this->user)
        ->post('/api/v1/documents', ['file' => pdfUpload('..\\..\\evil.pdf')])
        ->assertAccepted()
        ->assertJsonPath('data.original_filename', 'evil.pdf');

    expect(storedFiles())->toBe([Document::sole()->storage_path]);
});

it('requires authentication', function () {
    forbidInspect();

    $this->fromSpa()->post('/api/v1/documents', ['file' => pdfUpload()])
        ->assertUnauthorized();

    expect(storedFiles())->toBe([]);
});

it('rejects files that are not PDFs without running Poppler', function (Closure $makeFile, string $message) {
    forbidInspect();

    $this->fromSpa()->actingAs($this->user)
        ->post('/api/v1/documents', ['file' => $makeFile()])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.file.0', $message);

    expect(storedFiles())->toBe([])->and(Document::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'missing file' => [fn () => null, 'The file field is required.'],
    'executable renamed to .pdf' => [
        fn () => PdfFixture::upload('invoice.pdf', "MZ\x90\x00\x03\x00\x00\x00 This program cannot be run in DOS mode."),
        'The file must be a PDF.',
    ],
    'text file renamed to .pdf' => [
        fn () => PdfFixture::upload('notes.pdf', 'Just some plain text, not a PDF.'),
        'The file must be a PDF.',
    ],
    'PDF with another extension' => [
        fn () => PdfFixture::upload('report.txt', PdfFixture::bytes(['Some text on the page.'])),
        'The file must be a PDF.',
    ],
]);

it('rejects files larger than the limit without running Poppler', function () {
    forbidInspect();
    config(['documents.max_size_kb' => 1]);
    $upload = PdfFixture::upload('big.pdf', PdfFixture::bytes(array_fill(0, 10, str_repeat('Long text. ', 20))));

    expect($upload->getSize())->toBeGreaterThan(1024);

    $this->fromSpa()->actingAs($this->user)
        ->post('/api/v1/documents', ['file' => $upload])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'The file may not be larger than 1 KB.');

    expect(storedFiles())->toBe([]);
});

it('explains the limit when PHP rejected the upload as too large', function () {
    forbidInspect();
    $path = tempnam(sys_get_temp_dir(), 'upload-');
    // What PHP hands over for a file above upload_max_filesize: no content, error UPLOAD_ERR_INI_SIZE.
    $upload = new UploadedFile($path, 'huge.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, test: true);

    $this->fromSpa()->actingAs($this->user)
        ->post('/api/v1/documents', ['file' => $upload])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'The file could not be uploaded. PDFs may be at most 20 MB.');

    @unlink($path);
});

it('rejects PDFs that Poppler cannot open', function (PdfExtractionException $exception) {
    fakeInspect($exception);

    $this->fromSpa()->actingAs($this->user)
        ->post('/api/v1/documents', ['file' => pdfUpload()])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', $exception->getMessage());

    expect(storedFiles())->toBe([])->and(Document::count())->toBe(0);
})->with([
    'password protected' => fn () => PdfExtractionException::encrypted(),
    'damaged' => fn () => PdfExtractionException::invalidPdf('xref error'),
]);

it('rejects PDFs with more pages than allowed', function () {
    fakeInspect(301);

    $this->fromSpa()->actingAs($this->user)
        ->post('/api/v1/documents', ['file' => pdfUpload()])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'This PDF has 301 pages; the maximum is 300.');

    expect(storedFiles())->toBe([]);
});

it('accepts a PDF with exactly the maximum number of pages', function () {
    fakeInspect(300);

    $this->fromSpa()->actingAs($this->user)
        ->post('/api/v1/documents', ['file' => pdfUpload()])
        ->assertAccepted();
});

it('removes the stored file when the database insert fails', function () {
    fakeInspect(1);
    Document::creating(fn () => throw new RuntimeException('Simulated database failure'));

    $this->fromSpa()->actingAs($this->user)
        ->post('/api/v1/documents', ['file' => pdfUpload()])
        ->assertInternalServerError()
        ->assertJsonPath('code', 'server_error');

    expect(storedFiles())->toBe([]);
});

it('limits uploads to 10 per minute per user', function () {
    fakeInspect(1);

    foreach (range(1, 10) as $attempt) {
        $this->fromSpa()->actingAs($this->user)->post('/api/v1/documents', ['file' => pdfUpload()])->assertAccepted();
    }

    $this->fromSpa()->actingAs($this->user)
        ->post('/api/v1/documents', ['file' => pdfUpload()])
        ->assertTooManyRequests();
});

it('reads the real page count with Poppler', function () {
    $upload = PdfFixture::upload('three.pdf', PdfFixture::bytes([
        'First page with enough text.', 'Second page with enough text.', 'Third page with enough text.',
    ]));

    $this->fromSpa()->actingAs($this->user)
        ->post('/api/v1/documents', ['file' => $upload])
        ->assertAccepted()
        ->assertJsonPath('data.page_count', 3);
})->skip(fn () => ! popplerAvailable(), 'Poppler (pdfinfo) is not installed; see README.');
