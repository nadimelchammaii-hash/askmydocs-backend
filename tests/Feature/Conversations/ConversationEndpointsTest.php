<?php

use App\Enums\DocumentStatus;
use App\Enums\MessageStatus;
use App\Jobs\AnswerQuestion;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\MessageCitation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ChunkFixture;
use Tests\Support\FakeEmbeddingClient;
use Tests\Support\FakeStructuredOutputClient;

beforeEach(function () {
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->document = Document::factory()->for($this->owner)->ready()->create();
});

describe('starting a conversation', function () {
    it('stores the question with a pending answer and queues the answer job', function () {
        $response = $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/documents/{$this->document->id}/conversations", ['question' => '  What is the refund policy?  ']);

        $response->assertAccepted()
            ->assertJsonPath('data.document_id', $this->document->id)
            ->assertJsonPath('data.title', 'What is the refund policy?')
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.role', 'user')
            ->assertJsonPath('data.messages.0.content', 'What is the refund policy?')
            ->assertJsonPath('data.messages.1.role', 'assistant')
            ->assertJsonPath('data.messages.1.status', 'pending')
            ->assertJsonPath('data.messages.1.is_finished', false)
            ->assertJsonPath('data.messages.1.content', null)
            ->assertJsonPath('data.messages.1.citations', []);

        $conversation = Conversation::sole();
        expect($conversation->user_id)->toBe($this->owner->id);
        Queue::assertPushedOn('answers', AnswerQuestion::class, fn (AnswerQuestion $job) => $job->answer->id === $response->json('data.messages.1.id'));
    });

    it('shortens long titles at a word boundary', function () {
        $question = str_repeat('Explain the warranty conditions ', 6).'please?';

        $title = $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/documents/{$this->document->id}/conversations", ['question' => $question])
            ->json('data.title');

        expect(mb_strlen($title))->toBeLessThanOrEqual(81)->and($title)->toEndWith('…')->and($title)->not->toContain('warr…');
    });

    it('refuses documents that are not ready', function (DocumentStatus $status) {
        $this->document->update(['status' => $status]);

        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/documents/{$this->document->id}/conversations", ['question' => 'Anything?'])
            ->assertConflict()
            ->assertJsonPath('code', 'document_not_ready');

        expect(Conversation::count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([DocumentStatus::Queued, DocumentStatus::Embedding, DocumentStatus::Failed]);

    it('hides other users\' documents behind 404', function () {
        $this->fromSpa()->actingAs(User::factory()->create())
            ->postJson("/api/v1/documents/{$this->document->id}/conversations", ['question' => 'Anything?'])
            ->assertNotFound();

        expect(Conversation::count())->toBe(0);
    });
});

describe('validation', function () {
    it('rejects missing, blank and overly long questions', function (mixed $question) {
        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/documents/{$this->document->id}/conversations", ['question' => $question])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('question');

        Queue::assertNothingPushed();
    })->with([
        'missing' => [null],
        'blank' => ['    '],
        'not text' => [['a', 'b']],
        'too long' => [str_repeat('a', 1001)],
    ]);
});

describe('follow-up questions', function () {
    beforeEach(function () {
        $this->conversation = Conversation::factory()->for($this->document)->create(['user_id' => $this->owner->id]);
        Message::factory()->for($this->conversation)->create();
        Message::factory()->for($this->conversation)->answer()->create();
    });

    it('adds a question and a pending answer', function () {
        $response = $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/questions", ['question' => 'And with a receipt?']);

        $response->assertAccepted()
            ->assertJsonPath('data.question.role', 'user')
            ->assertJsonPath('data.question.content', 'And with a receipt?')
            ->assertJsonPath('data.answer.status', 'pending');

        expect($this->conversation->messages()->count())->toBe(4);
        Queue::assertPushed(AnswerQuestion::class, 1);
    });

    it('allows only one pending answer per conversation', function () {
        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/questions", ['question' => 'First?'])
            ->assertAccepted();

        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/questions", ['question' => 'Second?'])
            ->assertConflict()
            ->assertJsonPath('code', 'answer_pending');

        expect($this->conversation->messages()->count())->toBe(4);
        Queue::assertPushed(AnswerQuestion::class, 1);
    });

    it('refuses follow-ups while the document is not ready', function () {
        $this->document->update(['status' => DocumentStatus::Extracting]);

        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/questions", ['question' => 'More?'])
            ->assertConflict()
            ->assertJsonPath('code', 'document_not_ready');
    });

    it('hides other users\' conversations behind 404', function () {
        $this->fromSpa()->actingAs(User::factory()->create())
            ->postJson("/api/v1/conversations/{$this->conversation->id}/questions", ['question' => 'More?'])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        expect($this->conversation->messages()->count())->toBe(2);
    });
});

describe('polling a message', function () {
    beforeEach(function () {
        $this->conversation = Conversation::factory()->for($this->document)->create(['user_id' => $this->owner->id]);
    });

    it('returns a finished answer with numbered citations', function () {
        $answer = Message::factory()->for($this->conversation)->answer()->create(['content' => 'Within 30 days [1]. Receipt needed [2].']);
        MessageCitation::factory()->for($answer)->create(['position' => 1, 'page_number' => 2, 'quote' => 'A receipt is required']);
        MessageCitation::factory()->for($answer)->create(['position' => 0, 'page_number' => 2, 'quote' => 'within 30 days of purchase']);

        $this->fromSpa()->actingAs($this->owner)
            ->getJson("/api/v1/conversations/{$this->conversation->id}/messages/{$answer->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.is_finished', true)
            ->assertJsonPath('data.answer_found', true)
            ->assertJsonPath('data.citations', [
                ['number' => 1, 'page_number' => 2, 'quote' => 'within 30 days of purchase'],
                ['number' => 2, 'page_number' => 2, 'quote' => 'A receipt is required'],
            ])
            ->assertJsonMissingPath('data.input_tokens')
            ->assertJsonMissingPath('data.model');
    });

    it('returns the error of a failed answer', function () {
        $answer = Message::factory()->for($this->conversation)->failedAnswer()->create();

        $this->fromSpa()->actingAs($this->owner)
            ->getJson("/api/v1/conversations/{$this->conversation->id}/messages/{$answer->id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.is_finished', true)
            ->assertJsonPath('data.error_code', 'answer_failed');
    });

    it('answers 404 for a message of another conversation, even the user\'s own', function () {
        $otherConversation = Conversation::factory()->for($this->document)->create(['user_id' => $this->owner->id]);
        $message = Message::factory()->for($otherConversation)->create();

        $this->fromSpa()->actingAs($this->owner)
            ->getJson("/api/v1/conversations/{$this->conversation->id}/messages/{$message->id}")
            ->assertNotFound();
    });

    it('hides other users\' messages behind 404', function () {
        $message = Message::factory()->for($this->conversation)->create();

        $this->fromSpa()->actingAs(User::factory()->create())
            ->getJson("/api/v1/conversations/{$this->conversation->id}/messages/{$message->id}")
            ->assertNotFound();
    });
});

describe('limits', function () {
    it('limits questions per minute', function () {
        config(['answers.questions_per_minute' => 2]);

        foreach ([1, 2] as $i) {
            $this->fromSpa()->actingAs($this->owner)
                ->postJson("/api/v1/documents/{$this->document->id}/conversations", ['question' => "Question {$i}?"])
                ->assertAccepted();
        }

        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/documents/{$this->document->id}/conversations", ['question' => 'Question 3?'])
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'too_many_requests');
    });

    it('limits questions per day, with its own code', function () {
        config(['answers.questions_per_day' => 2]);

        foreach ([1, 2] as $i) {
            $this->fromSpa()->actingAs($this->owner)
                ->postJson("/api/v1/documents/{$this->document->id}/conversations", ['question' => "Question {$i}?"]);
        }

        $this->fromSpa()->actingAs($this->owner)
            ->postJson("/api/v1/documents/{$this->document->id}/conversations", ['question' => 'Question 3?'])
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'daily_limit_reached');

        // Another user is not affected.
        $other = User::factory()->create();
        $theirs = Document::factory()->for($other)->ready()->create();
        $this->fromSpa()->actingAs($other)
            ->postJson("/api/v1/documents/{$theirs->id}/conversations", ['question' => 'Mine?'])
            ->assertAccepted();
    });

    it('requires authentication', function (string $method, string $uri) {
        $conversation = Conversation::factory()->create();
        $message = Message::factory()->for($conversation)->create();
        $uri = strtr($uri, ['{document}' => $conversation->document_id, '{conversation}' => $conversation->id, '{message}' => $message->id]);

        $this->fromSpa()->json($method, $uri, ['question' => 'Hi?'])->assertUnauthorized();
    })->with([
        ['POST', '/api/v1/documents/{document}/conversations'],
        ['POST', '/api/v1/conversations/{conversation}/questions'],
        ['GET', '/api/v1/conversations/{conversation}/messages/{message}'],
    ]);
});

it('takes a question all the way to a cited answer', function () {
    // Fake OpenAI; the queued job is run as the worker would run it.
    FakeEmbeddingClient::bind()->vectorFor('refund', [1]);
    FakeStructuredOutputClient::bind()->reply([
        'answer_found' => true,
        'answer' => 'Unused items can be returned within 30 days [S1].',
        'citations' => [['source' => 'S1', 'quote' => 'return any unused item within 30 days']],
    ]);
    ChunkFixture::create($this->document, [[2, 'Customers may return any unused item within 30 days of purchase.', [1]]]);

    $response = $this->fromSpa()->actingAs($this->owner)
        ->postJson("/api/v1/documents/{$this->document->id}/conversations", ['question' => 'What is the refund policy?'])
        ->assertAccepted();

    $conversationId = $response->json('data.id');
    $answerId = $response->json('data.messages.1.id');

    $job = Queue::pushed(AnswerQuestion::class)->sole();
    app()->call([$job, 'handle']);

    $this->fromSpa()->actingAs($this->owner)
        ->getJson("/api/v1/conversations/{$conversationId}/messages/{$answerId}")
        ->assertJsonPath('data.status', MessageStatus::Completed->value)
        ->assertJsonPath('data.content', 'Unused items can be returned within 30 days [1].')
        ->assertJsonPath('data.citations.0.page_number', 2);
});
