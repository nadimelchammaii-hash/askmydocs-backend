<?php

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

it('identifies documents by ULID and starts them as queued', function () {
    $document = Document::factory()->create();

    expect(Str::isUlid($document->id))->toBeTrue()
        ->and($document->fresh()->status)->toBe(DocumentStatus::Queued);
});

it('stores files under the owner id with a generated name', function () {
    $document = Document::factory()->create(['original_filename' => '../../etc/passwd.pdf']);

    expect($document->storage_path)->toMatch('#^'.$document->user_id.'/[0-9a-z]{26}\.pdf$#');
});

it('returns pages in page order', function () {
    $document = Document::factory()->ready(3)->create();
    foreach ([3, 1, 2] as $number) {
        DocumentPage::factory()->for($document)->create(['page_number' => $number]);
    }

    expect($document->pages->pluck('page_number')->all())->toBe([1, 2, 3]);
});

it('rejects two rows for the same page of a document', function () {
    $document = Document::factory()->create();
    DocumentPage::factory()->for($document)->create(['page_number' => 1]);

    DocumentPage::factory()->for($document)->create(['page_number' => 1]);
})->throws(UniqueConstraintViolationException::class);

it('deletes pages together with their document', function () {
    $document = Document::factory()->create();
    DocumentPage::factory()->for($document)->count(2)->sequence(['page_number' => 1], ['page_number' => 2])->create();

    $document->delete();

    expect(DocumentPage::count())->toBe(0);
});

it('deletes documents and pages together with their owner', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    DocumentPage::factory()->for($document)->create();

    $user->delete();

    expect(Document::count())->toBe(0)
        ->and(DocumentPage::count())->toBe(0);
});

it('marks only ready and failed as finished', function () {
    expect(DocumentStatus::Queued->isFinished())->toBeFalse()
        ->and(DocumentStatus::Extracting->isFinished())->toBeFalse()
        ->and(DocumentStatus::Ready->isFinished())->toBeTrue()
        ->and(DocumentStatus::Failed->isFinished())->toBeTrue();
});

it('uses a private disk that is not served by URL', function () {
    $disk = config('filesystems.disks.'.config('documents.disk'));

    expect($disk['visibility'])->toBe('private')
        ->and($disk)->not->toHaveKey('url')
        ->and($disk['serve'] ?? false)->toBeFalse()
        ->and($disk['root'])->toStartWith(storage_path('app/private'));
});
