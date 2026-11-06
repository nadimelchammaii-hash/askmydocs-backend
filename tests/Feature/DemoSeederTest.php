<?php

use App\Jobs\ExtractDocumentText;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\PdfFixture;

/*
 * DemoSeeder imports the sample PDFs from the source folder into the hidden
 * template account, through the normal upload path (queued for processing).
 */

beforeEach(function () {
    Queue::fake();
    Storage::fake(config('documents.disk'));
    $this->folder = sys_get_temp_dir().'/demo-source-'.Str::random(8);
    File::makeDirectory($this->folder);
    config(['demo.source_path' => $this->folder]);
});

afterEach(function () {
    File::deleteDirectory($this->folder);
});

function addSample(string $folder, string $name, array $pages): void
{
    file_put_contents("{$folder}/{$name}", PdfFixture::bytes($pages));
}

function seededTemplate(): ?User
{
    return User::query()->where('email', config('demo.template_email'))->first();
}

it('imports the PDFs into the template account and queues their processing', function () {
    addSample($this->folder, 'Product manual.pdf', ['Setup', 'Warranty', 'Care']);
    addSample($this->folder, 'CV.pdf', ['Experience and projects']);

    $this->seed(DemoSeeder::class);

    $documents = seededTemplate()->documents()->orderBy('title')->get();
    expect($documents->pluck('title')->all())->toBe(['CV', 'Product manual'])
        ->and($documents->pluck('page_count')->all())->toBe([1, 3])
        ->and($documents->pluck('is_sample')->unique()->all())->toBe([true])
        ->and(seededTemplate()->isDemo())->toBeFalse();
    Queue::assertPushed(ExtractDocumentText::class, 2);
    $documents->each(fn ($document) => Storage::disk(config('documents.disk'))->assertExists($document->storage_path));
})->skip(fn () => ! popplerAvailable(), 'Poppler (pdfinfo) is not installed');

it('skips PDFs already imported and removes samples whose PDF is gone', function () {
    addSample($this->folder, 'Product manual.pdf', ['Setup']);
    addSample($this->folder, 'Old brochure.pdf', ['Old']);
    $this->seed(DemoSeeder::class);
    $manualId = seededTemplate()->documents()->where('title', 'Product manual')->value('id');

    unlink("{$this->folder}/Old brochure.pdf");
    $this->seed(DemoSeeder::class);

    expect(seededTemplate()->documents()->pluck('id')->all())->toBe([$manualId]);
    Queue::assertPushed(ExtractDocumentText::class, 2); // the first run only
})->skip(fn () => ! popplerAvailable(), 'Poppler (pdfinfo) is not installed');

it('does nothing without a source folder', function () {
    config(['demo.source_path' => $this->folder.'/missing']);

    $this->seed(DemoSeeder::class);

    expect(seededTemplate())->toBeNull();
    Queue::assertNothingPushed();
});
