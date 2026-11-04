<?php

use App\Actions\Documents\DeleteDocument;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Message;
use App\Models\MessageCitation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

it('belongs to the document\'s owner', function () {
    $conversation = Conversation::factory()->create();

    expect($conversation->user_id)->toBe($conversation->document->user_id)
        ->and($conversation->id)->toMatch('/^[0-9a-z]{26}$/');
});

it('keeps messages in the order they were written, even within one second', function () {
    $conversation = Conversation::factory()->create();
    $this->freezeTime(); // identical timestamps for every message

    $question = Message::factory()->for($conversation)->create();
    $answer = Message::factory()->for($conversation)->pendingAnswer()->create();
    $followUp = Message::factory()->for($conversation)->create();

    expect($conversation->messages->pluck('id')->all())->toBe([$question->id, $answer->id, $followUp->id]);
});

it('generates message IDs in increasing order, which the message order relies on', function () {
    // 1,000 IDs in a burst: many share the same millisecond.
    $ids = array_map(fn () => (new Message)->newUniqueId(), range(1, 1000));
    $sorted = $ids;
    sort($sorted, SORT_STRING);

    expect($ids)->toBe($sorted)->and(array_unique($ids))->toHaveCount(1000);
});

it('stores message roles, statuses and answer metadata', function () {
    $answer = Message::factory()->answer(found: false)->create()->fresh();

    expect($answer->role)->toBe(MessageRole::Assistant)
        ->and($answer->status)->toBe(MessageStatus::Completed)
        ->and($answer->answer_found)->toBeFalse()
        ->and($answer->input_tokens)->toBe(3000)
        ->and(MessageStatus::Pending->isFinished())->toBeFalse()
        ->and(MessageStatus::Failed->isFinished())->toBeTrue();
});

it('orders citations by position and keeps their position unique', function () {
    $answer = Message::factory()->answer()->create();
    MessageCitation::factory()->for($answer)->create(['position' => 1, 'page_number' => 9]);
    MessageCitation::factory()->for($answer)->create(['position' => 0, 'page_number' => 4]);

    expect($answer->citations->pluck('page_number')->all())->toBe([4, 9])
        ->and(fn () => MessageCitation::factory()->for($answer)->create(['position' => 0]))->toThrow(QueryException::class);
});

it('keeps a citation when its chunk is removed', function () {
    $chunk = DocumentChunk::factory()->create();
    $citation = MessageCitation::factory()->create(['document_chunk_id' => $chunk->id, 'page_number' => 3, 'quote' => 'within 30 days']);

    $chunk->delete();

    expect($citation->fresh())
        ->document_chunk_id->toBeNull()
        ->page_number->toBe(3)
        ->quote->toBe('within 30 days');
});

it('deletes conversations, messages and citations together with their document', function () {
    Storage::fake(config('documents.disk'));
    $conversation = Conversation::factory()->create();
    $answer = Message::factory()->for($conversation)->answer()->create();
    MessageCitation::factory()->for($answer)->create();
    $otherCitation = MessageCitation::factory()->create(); // another document's conversation

    app(DeleteDocument::class)->handle($conversation->document);

    expect(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(1)
        ->and(MessageCitation::pluck('id')->all())->toBe([$otherCitation->id]);
});

it('deletes conversations together with their user', function () {
    $conversation = Conversation::factory()->create();

    $conversation->user->delete();

    expect(Conversation::count())->toBe(0)->and(Document::count())->toBe(0);
});

describe('ConversationPolicy', function () {
    it('allows the owner', function (string $ability) {
        $conversation = Conversation::factory()->create();

        expect(Gate::forUser($conversation->user)->inspect($ability, $conversation)->allowed())->toBeTrue();
    })->with(['view', 'ask', 'update', 'delete']);

    it('hides other users\' conversations with a 404', function (string $ability) {
        $conversation = Conversation::factory()->create();

        $response = Gate::forUser(User::factory()->create())->inspect($ability, $conversation);

        expect($response->denied())->toBeTrue()->and($response->status())->toBe(404);
    })->with(['view', 'ask', 'update', 'delete']);
});
