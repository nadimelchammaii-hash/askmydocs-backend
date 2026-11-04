<?php

use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\PdfFixture;

beforeEach(function () {
    Storage::fake(config('documents.disk'));
    $this->owner = User::factory()->create();
    $this->stranger = User::factory()->create();
});

/** A ready document with its file on the (fake) disk and one stored page per text. */
function readyDocument(User $owner, array $pages = ['Page one text.', 'Page two text.']): Document
{
    $document = Document::factory()->for($owner)->ready(count($pages))->create(['original_filename' => 'Report.pdf']);
    Storage::disk(config('documents.disk'))->put($document->storage_path, PdfFixture::bytes($pages));

    foreach ($pages as $index => $text) {
        DocumentPage::factory()->for($document)->create([
            'page_number' => $index + 1, 'text' => $text, 'char_count' => mb_strlen($text),
        ]);
    }

    return $document;
}

describe('list', function () {
    it('lists only the user\'s own documents, newest first', function () {
        $old = Document::factory()->for($this->owner)->create(['created_at' => now()->subDay()]);
        $new = Document::factory()->for($this->owner)->create();
        Document::factory()->for($this->stranger)->create();

        $this->fromSpa()->actingAs($this->owner)->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.1.id', $old->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 20);
    });

    it('paginates', function () {
        Document::factory()->for($this->owner)->count(21)->create();

        $this->fromSpa()->actingAs($this->owner)->getJson('/api/v1/documents?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('requires authentication', function () {
        $this->fromSpa()->getJson('/api/v1/documents')->assertUnauthorized();
    });
});

describe('show', function () {
    it('returns the document with its processing state', function () {
        $document = Document::factory()->for($this->owner)->failed('encrypted_pdf', 'This PDF is password-protected.')->create();

        $this->fromSpa()->actingAs($this->owner)->getJson("/api/v1/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.is_finished', true)
            ->assertJsonPath('data.error_code', 'encrypted_pdf')
            ->assertJsonPath('data.error_message', 'This PDF is password-protected.');
    });

    it('answers 404 for unknown and malformed ids', function (string $id) {
        $this->fromSpa()->actingAs($this->owner)->getJson("/api/v1/documents/{$id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    })->with([
        'unknown ULID' => fn () => (string) Str::ulid(),
        'not a ULID' => 'not-a-ulid',
        'SQL-looking' => "1' OR '1'='1",
    ]);
});

describe('file', function () {
    it('streams the PDF inline to its owner', function () {
        $document = readyDocument($this->owner);

        $response = $this->fromSpa()->actingAs($this->owner)->get("/api/v1/documents/{$document->id}/file");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename=Report.pdf');

        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->streamedContent())->toStartWith('%PDF-');
    });

    it('answers 404 when the file is missing from storage', function () {
        $document = Document::factory()->for($this->owner)->create();

        $this->fromSpa()->actingAs($this->owner)->getJson("/api/v1/documents/{$document->id}/file")
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });
});

describe('pages', function () {
    it('returns the text of one page', function () {
        $document = readyDocument($this->owner);

        $this->fromSpa()->actingAs($this->owner)->getJson("/api/v1/documents/{$document->id}/pages/2")
            ->assertOk()
            ->assertExactJson(['data' => [
                'document_id' => $document->id, 'page_number' => 2, 'text' => 'Page two text.', 'char_count' => 14,
            ]]);
    });

    it('answers 404 for pages that do not exist', function (string $page) {
        $document = readyDocument($this->owner);

        $this->fromSpa()->actingAs($this->owner)->getJson("/api/v1/documents/{$document->id}/pages/{$page}")
            ->assertNotFound();
    })->with(['0', '3', 'abc']);
});

describe('delete', function () {
    it('deletes the document, its pages and its file', function () {
        $document = readyDocument($this->owner);
        $other = readyDocument($this->owner);

        $this->fromSpa()->actingAs($this->owner)->deleteJson("/api/v1/documents/{$document->id}")
            ->assertNoContent();

        expect(Document::find($document->id))->toBeNull()
            ->and(DocumentPage::where('document_id', $document->id)->count())->toBe(0)
            ->and(Storage::disk(config('documents.disk'))->exists($document->storage_path))->toBeFalse()
            // Other documents are untouched.
            ->and(DocumentPage::where('document_id', $other->id)->count())->toBe(2)
            ->and(Storage::disk(config('documents.disk'))->exists($other->storage_path))->toBeTrue();
    });

    it('still deletes the document when its file is already gone', function () {
        $document = Document::factory()->for($this->owner)->create();

        $this->fromSpa()->actingAs($this->owner)->deleteJson("/api/v1/documents/{$document->id}")
            ->assertNoContent();

        expect(Document::count())->toBe(0);
    });
});

describe('ownership', function () {
    it('hides other users\' documents behind 404 on every endpoint', function (string $method, string $suffix) {
        $document = readyDocument($this->owner);

        $this->fromSpa()->actingAs($this->stranger)
            ->json($method, "/api/v1/documents/{$document->id}{$suffix}")
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found')
            ->assertJsonPath('message', 'Resource not found.');

        // Nothing was changed by the stranger's request.
        expect(Document::find($document->id))->not->toBeNull()
            ->and(Storage::disk(config('documents.disk'))->exists($document->storage_path))->toBeTrue();
    })->with([
        'show' => ['GET', ''],
        'file' => ['GET', '/file'],
        'page' => ['GET', '/pages/1'],
        'delete' => ['DELETE', ''],
    ]);

    it('requires authentication on every endpoint', function (string $method, string $suffix) {
        $document = readyDocument($this->owner);

        $this->fromSpa()->json($method, "/api/v1/documents/{$document->id}{$suffix}")
            ->assertUnauthorized();
    })->with([
        'show' => ['GET', ''],
        'file' => ['GET', '/file'],
        'page' => ['GET', '/pages/1'],
        'delete' => ['DELETE', ''],
    ]);
});
