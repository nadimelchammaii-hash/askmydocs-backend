<?php

use App\Console\Commands\FailStalledWork;
use App\Enums\DocumentStatus;
use App\Enums\MessageStatus;
use App\Http\Middleware\SecurityHeaders;
use App\Jobs\AnswerQuestion;
use App\Jobs\ExtractDocumentText;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\Answering\AnswerPromptBuilder;
use App\Services\Answering\CitationVerifier;
use App\Services\OpenAi\StructuredOutputClient;
use App\Services\Retrieval\ChunkRetriever;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function assertSecurityHeaders(TestResponse $response): void
{
    foreach (SecurityHeaders::HEADERS as $name => $value) {
        $response->assertHeader($name, $value);
    }
}

describe('security headers', function () {
    it('are sent with successful, failed and file responses', function () {
        Storage::fake(config('documents.disk'));
        $user = User::factory()->create();
        $document = Document::factory()->for($user)->ready()->create();
        Storage::disk(config('documents.disk'))->put($document->storage_path, '%PDF-1.7 test');

        assertSecurityHeaders($this->getJson('/api/v1/stats')->assertUnauthorized());
        assertSecurityHeaders($this->get('/up')->assertOk());
        assertSecurityHeaders($this->fromSpa()->actingAs($user)->getJson('/api/v1/user')->assertOk());
        assertSecurityHeaders($this->fromSpa()->actingAs($user)->getJson('/api/v1/documents/01jdoesnotexist000000000000')->assertNotFound());
        assertSecurityHeaders($this->fromSpa()->actingAs($user)->get("/api/v1/documents/{$document->id}/file")->assertOk());
    });
});

describe('general API rate limit', function () {
    it('limits requests per user, separately for each user', function () {
        config(['app.api_requests_per_minute' => 3]);
        [$ana, $ben] = User::factory()->count(2)->create();

        foreach (range(1, 3) as $i) {
            $this->fromSpa()->actingAs($ana)->getJson('/api/v1/stats')->assertOk();
        }

        $this->fromSpa()->actingAs($ana)->getJson('/api/v1/documents')
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');

        $this->fromSpa()->actingAs($ben)->getJson('/api/v1/stats')->assertOk();

        // A new minute: allowed again.
        $this->travel(61)->seconds();
        $this->fromSpa()->actingAs($ana)->getJson('/api/v1/stats')->assertOk();
    });

    it('limits guests by IP address on public routes', function () {
        // Protected routes turn guests away (401) before any limit is counted.
        config(['app.api_requests_per_minute' => 2]);
        $attempt = fn () => $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'wrong']);

        $attempt()->assertUnprocessable();
        $attempt()->assertUnprocessable();
        $attempt()->assertTooManyRequests();
    });
});

describe('stalled work', function () {
    beforeEach(function () {
        Queue::fake();
        config(['answers.stalled_after_minutes' => 10, 'documents.stalled_after_minutes' => 60]);
        $this->conversation = Conversation::factory()->create();
    });

    function pendingAnswerSince(Conversation $conversation, int $minutesAgo): Message
    {
        $answer = Message::factory()->for($conversation)->pendingAnswer()->create();
        Message::whereKey($answer->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

        return $answer;
    }

    function documentSince(DocumentStatus $status, int $minutesAgo): Document
    {
        $document = Document::factory()->create(['status' => $status]);
        Document::whereKey($document->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

        return $document;
    }

    it('fails answers pending for too long and leaves the others alone', function () {
        $stalled = pendingAnswerSince($this->conversation, 11);
        $recent = pendingAnswerSince($this->conversation, 9);
        $completed = Message::factory()->for($this->conversation)->answer()->create();
        Message::whereKey($completed->id)->update(['updated_at' => now()->subDay()]);

        $this->artisan('work:fail-stalled')->assertSuccessful();

        expect($stalled->fresh())
            ->status->toBe(MessageStatus::Failed)
            ->error_code->toBe(FailStalledWork::ANSWER_CODE)
            ->error_message->toBe(FailStalledWork::ANSWER_MESSAGE)
            ->and($recent->fresh()->status)->toBe(MessageStatus::Pending)
            ->and($completed->fresh()->status)->toBe(MessageStatus::Completed);
    });

    it('fails documents processing for too long and leaves the others alone', function () {
        $stalled = [
            documentSince(DocumentStatus::Queued, 61),
            documentSince(DocumentStatus::Extracting, 61),
            documentSince(DocumentStatus::Embedding, 61),
        ];
        $recent = documentSince(DocumentStatus::Embedding, 30);
        $ready = documentSince(DocumentStatus::Ready, 600);

        $this->artisan('work:fail-stalled')->assertSuccessful();

        foreach ($stalled as $document) {
            expect($document->fresh())
                ->status->toBe(DocumentStatus::Failed)
                ->error_code->toBe(FailStalledWork::DOCUMENT_CODE);
        }
        expect($recent->fresh()->status)->toBe(DocumentStatus::Embedding)
            ->and($ready->fresh()->status)->toBe(DocumentStatus::Ready);
    });

    it('lets the conversation continue: the failed answer can be retried and is not swept again', function () {
        $stalled = pendingAnswerSince($this->conversation, 11);
        Message::factory()->for($this->conversation)->create(['id' => (string) Str::ulid(now()->subHour()), 'content' => 'Question?']);
        $this->artisan('work:fail-stalled');

        $this->fromSpa()->actingAs($this->conversation->user)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/messages/{$stalled->id}/retry")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $this->artisan('work:fail-stalled');
        expect($stalled->fresh()->status)->toBe(MessageStatus::Pending);
    });

    it('makes jobs that run late do nothing (no OpenAI call)', function () {
        // Any HTTP request would fail the test (TestCase::preventStrayRequests).
        $answer = pendingAnswerSince($this->conversation, 11);
        $document = documentSince(DocumentStatus::Queued, 61);
        $this->artisan('work:fail-stalled');

        (new AnswerQuestion($answer->fresh()))->handle(...array_map(app(...), [
            ChunkRetriever::class,
            AnswerPromptBuilder::class,
            StructuredOutputClient::class,
            CitationVerifier::class,
        ]));
        app()->call([new ExtractDocumentText($document->fresh()), 'handle']);

        expect($answer->fresh()->status)->toBe(MessageStatus::Failed)
            ->and($document->fresh()->status)->toBe(DocumentStatus::Failed);
    });
});

it('schedules the maintenance commands', function () {
    Artisan::call('schedule:list');
    $output = Artisan::output();

    expect($output)
        ->toContain('work:fail-stalled')
        ->toContain('queue:prune-failed --hours=168')
        ->toContain('auth:clear-resets')
        ->toContain('documents:prune-orphans');
});
