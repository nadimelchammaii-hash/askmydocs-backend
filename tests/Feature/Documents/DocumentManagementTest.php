<?php

use App\Enums\DocumentStatus;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();

    // IDs of the documents a listing returns, in order.
    $this->listedIds = fn (string $query = ''): array => collect(
        $this->fromSpa()->actingAs($this->owner)->getJson("/api/v1/documents{$query}")->assertOk()->json('data'),
    )->pluck('id')->all();
});

describe('filtering the document list', function () {
    it('filters by status', function () {
        $ready = Document::factory()->for($this->owner)->ready()->create();
        Document::factory()->for($this->owner)->failed()->create();
        Document::factory()->for($this->owner)->create(['status' => DocumentStatus::Embedding]);

        expect(($this->listedIds)('?status=ready'))->toBe([$ready->id]);
    });

    it('searches titles, ignoring case', function () {
        $match = Document::factory()->for($this->owner)->create(['title' => 'Annual REPORT 2025']);
        Document::factory()->for($this->owner)->create(['title' => 'Store handbook']);

        expect(($this->listedIds)('?search=report'))->toBe([$match->id]);
    });

    it('treats % and _ in the search literally', function () {
        $literal = Document::factory()->for($this->owner)->create(['title' => 'Growth 100% report']);
        Document::factory()->for($this->owner)->create(['title' => 'Growth 1000 report']);
        $underscore = Document::factory()->for($this->owner)->create(['title' => 'file_name']);
        Document::factory()->for($this->owner)->create(['title' => 'fileXname']);

        expect(($this->listedIds)('?search='.urlencode('100%')))->toBe([$literal->id])
            ->and(($this->listedIds)('?search=file_'))->toBe([$underscore->id]);
    });

    it('combines filters and ignores an empty search', function () {
        $readyReport = Document::factory()->for($this->owner)->ready()->create(['title' => 'Report A']);
        Document::factory()->for($this->owner)->failed()->create(['title' => 'Report B']);

        expect(($this->listedIds)('?status=ready&search=report'))->toBe([$readyReport->id])
            ->and(($this->listedIds)('?search='))->toHaveCount(2);
    });

    it('never shows other users\' documents', function () {
        Document::factory()->ready()->create(['title' => 'Secret report']);

        expect(($this->listedIds)('?search=report'))->toBe([]);
    });

    it('rejects invalid filters', function (string $query, string $field) {
        $this->fromSpa()->actingAs($this->owner)->getJson("/api/v1/documents{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'unknown status' => ['?status=done', 'status'],
        'search too long' => ['?search='.str_repeat('a', 101), 'search'],
    ]);

    it('shows how many conversations each document has', function () {
        $document = Document::factory()->for($this->owner)->ready()->create();
        Conversation::factory()->for($document)->count(2)->create(['user_id' => $this->owner->id]);

        $this->fromSpa()->actingAs($this->owner)->getJson('/api/v1/documents')
            ->assertJsonPath('data.0.conversations_count', 2);
    });
});

describe('renaming a document', function () {
    it('renames the document', function () {
        $document = Document::factory()->for($this->owner)->ready()->create();

        $this->fromSpa()->actingAs($this->owner)
            ->patchJson("/api/v1/documents/{$document->id}", ['title' => '  Store Handbook 2026  '])
            ->assertOk()
            ->assertJsonPath('data.title', 'Store Handbook 2026');

        expect($document->fresh()->title)->toBe('Store Handbook 2026');
    });

    it('validates the title', function (mixed $title) {
        $document = Document::factory()->for($this->owner)->create();

        $this->fromSpa()->actingAs($this->owner)
            ->patchJson("/api/v1/documents/{$document->id}", ['title' => $title])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    })->with([[null], ['  '], [str_repeat('a', 256)]]);

    it('only renames: other fields are ignored', function () {
        $document = Document::factory()->for($this->owner)->failed()->create();

        $this->fromSpa()->actingAs($this->owner)
            ->patchJson("/api/v1/documents/{$document->id}", ['title' => 'New', 'status' => 'ready', 'user_id' => 999])
            ->assertOk();

        expect($document->fresh())->status->toBe(DocumentStatus::Failed)->user_id->toBe($this->owner->id);
    });

    it('hides other users\' documents behind 404', function () {
        $document = Document::factory()->create(['title' => 'Theirs']);

        $this->fromSpa()->actingAs($this->owner)
            ->patchJson("/api/v1/documents/{$document->id}", ['title' => 'Mine now'])
            ->assertNotFound();

        expect($document->fresh()->title)->toBe('Theirs');
    });
});
