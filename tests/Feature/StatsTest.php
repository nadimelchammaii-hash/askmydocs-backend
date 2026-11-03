<?php

use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
});

function askQuestion(User $user, Document $document): TestResponse
{
    return test()->fromSpa()->actingAs($user)
        ->postJson("/api/v1/documents/{$document->id}/conversations", ['question' => 'What does it say?']);
}

it('requires authentication', function () {
    $this->getJson('/api/v1/stats')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

it('counts only the user\'s own documents, pages and conversations', function () {
    $ready = Document::factory()->for($this->user)->ready(pages: 12)->create();
    Document::factory()->for($this->user)->ready(pages: 30)->create();
    Document::factory()->for($this->user)->create(); // queued
    Document::factory()->for($this->user)->create(['status' => 'embedding', 'page_count' => 50]);
    Document::factory()->for($this->user)->failed()->create(['page_count' => 7]);
    Conversation::factory()->count(2)->create(['document_id' => $ready->id]);

    // Someone else's data is not counted.
    $other = User::factory()->create();
    Conversation::factory()->create(['document_id' => Document::factory()->for($other)->ready(pages: 99)->create()->id]);

    $this->actingAs($this->user)->getJson('/api/v1/stats')
        ->assertOk()
        ->assertJsonPath('data.documents', ['total' => 5, 'ready' => 2, 'processing' => 2, 'failed' => 1])
        // Only ready documents can be asked about.
        ->assertJsonPath('data.pages_indexed', 42)
        ->assertJsonPath('data.conversations', 2);
});

it('returns zeros for a new user', function () {
    $this->actingAs($this->user)->getJson('/api/v1/stats')
        ->assertOk()
        ->assertJsonPath('data.documents', ['total' => 0, 'ready' => 0, 'processing' => 0, 'failed' => 0])
        ->assertJsonPath('data.pages_indexed', 0)
        ->assertJsonPath('data.conversations', 0)
        ->assertJsonPath('data.questions_today.used', 0);
});

it('reports the questions asked today against the daily limit', function () {
    config(['answers.questions_per_day' => 5]);
    $this->travelTo(now()->setTime(15, 30));
    $document = Document::factory()->for($this->user)->ready()->create();

    askQuestion($this->user, $document)->assertAccepted();
    askQuestion($this->user, $document)->assertAccepted();

    $this->actingAs($this->user)->getJson('/api/v1/stats')
        ->assertJsonPath('data.questions_today', [
            'used' => 2,
            'limit' => 5,
            'remaining' => 3,
            'resets_at' => now()->addDay()->startOfDay()->toIso8601String(),
        ]);

    $other = User::factory()->create();
    $this->actingAs($other)->getJson('/api/v1/stats')->assertJsonPath('data.questions_today.used', 0);
});

describe('question limits', function () {
    it('allows the configured number of questions per minute', function () {
        config(['answers.questions_per_minute' => 3, 'answers.questions_per_day' => 100]);
        $document = Document::factory()->for($this->user)->ready()->create();

        foreach (range(1, 3) as $i) {
            askQuestion($this->user, $document)->assertAccepted();
        }

        askQuestion($this->user, $document)->assertTooManyRequests()->assertJsonPath('code', 'too_many_requests');
    });

    it('keeps the daily limit after the minute is over, until the next day', function () {
        config(['answers.questions_per_minute' => 10, 'answers.questions_per_day' => 2]);
        $this->travelTo(now()->setTime(10, 0));
        $document = Document::factory()->for($this->user)->ready()->create();

        askQuestion($this->user, $document)->assertAccepted();
        askQuestion($this->user, $document)->assertAccepted();

        $this->travel(5)->minutes();
        askQuestion($this->user, $document)->assertTooManyRequests()->assertJsonPath('code', 'daily_limit_reached');

        $this->travelTo(now()->addDay()->startOfDay()->addMinute());
        askQuestion($this->user, $document)->assertAccepted();
    });
});
