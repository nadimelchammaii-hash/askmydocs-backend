<?php

use App\Enums\DocumentStatus;
use App\Enums\MessageStatus;
use App\Jobs\AnswerQuestion;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\MessageCitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->document = Document::factory()->for($this->owner)->ready()->create(['title' => 'Store Handbook']);
});

function conversationOf(User $owner, Document $document, array $attributes = []): Conversation
{
    return Conversation::factory()->for($document)->create(['user_id' => $owner->id, ...$attributes]);
}

describe('listing conversations', function () {
    it('lists only the user\'s conversations, most recently active first', function () {
        $old = conversationOf($this->owner, $this->document, ['updated_at' => now()->subDay()]);
        $recent = conversationOf($this->owner, $this->document);
        Message::factory()->for($recent)->count(2)->create();
        Conversation::factory()->create(); // someone else's

        $this->fromSpa()->actingAs($this->owner)->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $recent->id)
            ->assertJsonPath('data.0.document', ['id' => $this->document->id, 'title' => 'Store Handbook'])
            ->assertJsonPath('data.0.messages_count', 2)
            ->assertJsonPath('data.1.id', $old->id)
            ->assertJsonMissingPath('data.0.messages')
            ->assertJsonPath('meta.total', 2);
    });

    it('filters by document', function () {
        $other = Document::factory()->for($this->owner)->ready()->create();
        $mine = conversationOf($this->owner, $this->document);
        conversationOf($this->owner, $other);

        $this->fromSpa()->actingAs($this->owner)->getJson("/api/v1/conversations?document_id={$this->document->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    });

    it('returns nothing when filtering by another user\'s document', function () {
        $theirs = Conversation::factory()->create();

        $this->fromSpa()->actingAs($this->owner)->getJson("/api/v1/conversations?document_id={$theirs->document_id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('rejects a malformed document filter', function () {
        $this->fromSpa()->actingAs($this->owner)->getJson('/api/v1/conversations?document_id=nope')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_id');
    });

    it('uses the same number of queries for 3 or 30 conversations', function () {
        $count = function (int $conversations) {
            DB::table('conversations')->delete();
            foreach (range(1, $conversations) as $ignored) {
                Message::factory()->for(conversationOf($this->owner, Document::factory()->for($this->owner)->ready()->create()))->create();
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->fromSpa()->actingAs($this->owner)->getJson('/api/v1/conversations')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        expect($count(3))->toBe($count(30));
    });
});

describe('reading a conversation', function () {
    it('returns every message in order, with citations', function () {
        $conversation = conversationOf($this->owner, $this->document);
        $question = Message::factory()->for($conversation)->create(['content' => 'Refunds?']);
        $answer = Message::factory()->for($conversation)->answer()->create(['content' => 'Within 30 days [1].']);
        MessageCitation::factory()->for($answer)->create(['position' => 0, 'number' => 1, 'page_number' => 2, 'quote' => 'within 30 days']);

        $this->fromSpa()->actingAs($this->owner)->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.document.title', 'Store Handbook')
            ->assertJsonPath('data.messages.0.id', $question->id)
            ->assertJsonPath('data.messages.1.id', $answer->id)
            ->assertJsonPath('data.messages.1.citations', [['number' => 1, 'page_number' => 2, 'quote' => 'within 30 days']]);
    });
});

describe('renaming and deleting', function () {
    it('renames a conversation', function () {
        $conversation = conversationOf($this->owner, $this->document);

        $this->fromSpa()->actingAs($this->owner)
            ->patchJson("/api/v1/conversations/{$conversation->id}", ['title' => '  Refund questions  '])
            ->assertOk()
            ->assertJsonPath('data.title', 'Refund questions');
    });

    it('validates the new title', function (mixed $title) {
        $conversation = conversationOf($this->owner, $this->document);

        $this->fromSpa()->actingAs($this->owner)
            ->patchJson("/api/v1/conversations/{$conversation->id}", ['title' => $title])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    })->with([[null], ['   '], [str_repeat('a', 201)]]);

    it('deletes a conversation with its messages and citations, leaving the document', function () {
        $conversation = conversationOf($this->owner, $this->document);
        $answer = Message::factory()->for($conversation)->answer()->create();
        MessageCitation::factory()->for($answer)->create();
        $other = conversationOf($this->owner, $this->document);

        $this->fromSpa()->actingAs($this->owner)->deleteJson("/api/v1/conversations/{$conversation->id}")
            ->assertNoContent();

        expect(Conversation::pluck('id')->all())->toBe([$other->id])
            ->and(Message::count())->toBe(0)
            ->and(MessageCitation::count())->toBe(0)
            ->and($this->document->fresh())->not->toBeNull();
    });
});

describe('retrying a failed answer', function () {
    beforeEach(function () {
        $this->conversation = conversationOf($this->owner, $this->document);
        Message::factory()->for($this->conversation)->create(['content' => 'Refunds?']);
        $this->failed = Message::factory()->for($this->conversation)->failedAnswer()->create();
    });

    it('makes the answer pending again and queues it', function () {
        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/messages/{$this->failed->id}/retry")
            ->assertAccepted()
            ->assertJsonPath('data.id', $this->failed->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.error_code', null);

        expect($this->failed->refresh()->status)->toBe(MessageStatus::Pending);
        Queue::assertPushedOn('answers', AnswerQuestion::class, fn (AnswerQuestion $job) => $job->answer->is($this->failed));
    });

    it('refuses to retry anything but a failed answer', function (string $state) {
        $message = match ($state) {
            'question' => Message::factory()->for($this->conversation)->create(),
            'completed answer' => Message::factory()->for($this->conversation)->answer()->create(),
        };

        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/messages/{$message->id}/retry")
            ->assertConflict()
            ->assertJsonPath('code', 'answer_not_failed');

        Queue::assertNothingPushed();
    })->with(['question', 'completed answer']);

    it('refuses while another answer is pending', function () {
        Message::factory()->for($this->conversation)->create();
        Message::factory()->for($this->conversation)->pendingAnswer()->create();

        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/messages/{$this->failed->id}/retry")
            ->assertConflict()
            ->assertJsonPath('code', 'answer_pending');
    });

    it('refuses while the document is not ready', function () {
        $this->document->update(['status' => DocumentStatus::Embedding]);

        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/messages/{$this->failed->id}/retry")
            ->assertConflict()
            ->assertJsonPath('code', 'document_not_ready');
    });

    it('counts toward the question limits', function () {
        config(['answers.questions_per_minute' => 1]);
        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/documents/{$this->document->id}/conversations", ['question' => 'Q?'])->assertAccepted();

        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/messages/{$this->failed->id}/retry")
            ->assertTooManyRequests();
    });
});

describe('ownership', function () {
    it('hides other users\' conversations behind 404 on every endpoint', function (string $method, string $suffix) {
        $conversation = Conversation::factory()->create();
        $failed = Message::factory()->for($conversation)->failedAnswer()->create();
        $uri = '/api/v1/conversations/'.$conversation->id.str_replace('{message}', $failed->id, $suffix);

        $this->fromSpa()->actingAs($this->owner)->json($method, $uri, ['title' => 'Mine now'])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        expect($conversation->fresh())->not->toBeNull()->title->not->toBe('Mine now')
            ->and($failed->fresh()->status)->toBe(MessageStatus::Failed);
    })->with([
        'show' => ['GET', ''],
        'rename' => ['PATCH', ''],
        'delete' => ['DELETE', ''],
        'retry answer' => ['POST', '/messages/{message}/retry'],
    ]);

    it('answers 404 when retrying a message of another conversation', function () {
        $mine = conversationOf($this->owner, $this->document);
        $otherFailed = Message::factory()->for(conversationOf($this->owner, $this->document))->failedAnswer()->create();

        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/conversations/{$mine->id}/messages/{$otherFailed->id}/retry")
            ->assertNotFound();
    });

    it('requires authentication', function () {
        $this->fromSpa()->getJson('/api/v1/conversations')->assertUnauthorized();
    });
});
