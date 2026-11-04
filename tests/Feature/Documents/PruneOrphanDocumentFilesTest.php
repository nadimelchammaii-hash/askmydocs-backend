<?php

use App\Models\Document;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->disk = Storage::fake(config('documents.disk'));
});

/** Puts a file on the fake disk, last modified the given number of minutes ago. */
function putFile(Filesystem $disk, string $path, int $minutesAgo): string
{
    $disk->put($path, '%PDF-1.4 test');
    touch($disk->path($path), now()->subMinutes($minutesAgo)->getTimestamp());

    return $path;
}

function orphanPath(): string
{
    return '42/'.(new Document)->newUniqueId().'.pdf'; // the format uploads use
}

it('deletes old files that no document refers to', function () {
    $orphan = putFile($this->disk, orphanPath(), 120);
    $document = Document::factory()->create();
    $referenced = putFile($this->disk, $document->storage_path, 120);

    $this->artisan('documents:prune-orphans')
        ->expectsOutput("Deleted: {$orphan}")
        ->expectsOutput('1 orphaned file(s) deleted.')
        ->assertSuccessful();

    expect($this->disk->exists($orphan))->toBeFalse()
        ->and($this->disk->exists($referenced))->toBeTrue();
});

it('keeps recent files, which may belong to an upload in progress', function () {
    $recent = putFile($this->disk, orphanPath(), 5);

    $this->artisan('documents:prune-orphans')->assertSuccessful();

    expect($this->disk->exists($recent))->toBeTrue();
});

it('honours a custom minimum age', function () {
    $file = putFile($this->disk, orphanPath(), 5);

    $this->artisan('documents:prune-orphans', ['--min-age' => 1])->assertSuccessful();

    expect($this->disk->exists($file))->toBeFalse();
});

it('only reports in dry-run mode', function () {
    $orphan = putFile($this->disk, orphanPath(), 120);

    $this->artisan('documents:prune-orphans', ['--dry-run' => true])
        ->expectsOutput("Would delete: {$orphan}")
        ->expectsOutput('1 orphaned file(s) found (dry run, nothing deleted).')
        ->assertSuccessful();

    expect($this->disk->exists($orphan))->toBeTrue();
});

it('never deletes files it did not create', function () {
    $foreign = putFile($this->disk, 'notes/readme.txt', 120);

    $this->artisan('documents:prune-orphans')
        ->expectsOutputToContain('Skipping unexpected file: notes/readme.txt')
        ->assertSuccessful();

    expect($this->disk->exists($foreign))->toBeTrue();
});
