<?php

use App\Enums\DocumentStatus;
use App\Jobs\ExtractDocumentText;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->owner = User::factory()->create();
});

it('re-queues a failed document', function () {
    $document = Document::factory()->for($this->owner)->failed('extraction_timeout', 'Reading this PDF took too long.')->create();

    $this->fromSpa()->actingAs($this->owner)->postJson("/api/v1/documents/{$document->id}/retry")
        ->assertAccepted()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.is_finished', false)
        ->assertJsonPath('data.error_code', null)
        ->assertJsonPath('data.error_message', null);

    expect($document->refresh()->status)->toBe(DocumentStatus::Queued);
    Queue::assertPushedOn('documents', ExtractDocumentText::class, fn (ExtractDocumentText $job) => $job->document->is($document));
});

it('refuses to retry documents that have not failed', function (DocumentStatus $status) {
    $document = Document::factory()->for($this->owner)->create(['status' => $status]);

    $this->fromSpa()->actingAs($this->owner)->postJson("/api/v1/documents/{$document->id}/retry")
        ->assertConflict()
        ->assertJsonPath('code', 'conflict')
        ->assertJsonPath('message', 'Only documents whose processing failed can be retried.');

    expect($document->refresh()->status)->toBe($status);
    Queue::assertNothingPushed();
})->with([DocumentStatus::Queued, DocumentStatus::Extracting, DocumentStatus::Ready]);

it('queues the job only once for repeated retries', function () {
    $document = Document::factory()->for($this->owner)->failed()->create();

    $this->fromSpa()->actingAs($this->owner)->postJson("/api/v1/documents/{$document->id}/retry")->assertAccepted();
    $this->fromSpa()->actingAs($this->owner)->postJson("/api/v1/documents/{$document->id}/retry")->assertConflict();

    Queue::assertPushed(ExtractDocumentText::class, 1);
});

it('hides other users\' documents behind 404', function () {
    $document = Document::factory()->failed()->create();

    $this->fromSpa()->actingAs($this->owner)->postJson("/api/v1/documents/{$document->id}/retry")
        ->assertNotFound();

    expect($document->refresh()->status)->toBe(DocumentStatus::Failed);
    Queue::assertNothingPushed();
});

it('requires authentication', function () {
    $document = Document::factory()->failed()->create();

    $this->fromSpa()->postJson("/api/v1/documents/{$document->id}/retry")->assertUnauthorized();
});
