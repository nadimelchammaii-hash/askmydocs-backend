<?php

use App\Enums\DocumentStatus;
use App\Enums\MessageStatus;
use App\Jobs\AnswerQuestion;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageCitation;
use App\Services\Answering\AnswerPromptBuilder;
use App\Services\Answering\CitationVerifier;
use App\Services\OpenAi\OpenAiException;
use App\Services\OpenAi\StructuredOutputClient;
use App\Services\Retrieval\ChunkRetriever;
use Illuminate\Support\Facades\Log;
use Tests\Support\ChunkFixture;
use Tests\Support\FakeEmbeddingClient;
use Tests\Support\FakeStructuredOutputClient;

const REFUND_TEXT = "Refund and Return Policy.\nCustomers may return any unused item within 30 days of purchase.";
const HOURS_TEXT = 'Stores are open Monday to Saturday from 9:00 to 19:00. All stores are closed on Sundays.';

beforeEach(function () {
    // Questions mentioning "refund" point along axis 1, "weekend" along axis 2,
    // "weather" along axis 3 (unrelated to every chunk).
    $this->embeddings = FakeEmbeddingClient::bind()
        ->vectorFor('refund', [1])
        ->vectorFor('weekend', [0, 1])
        ->vectorFor('weather', [0, 0, 1]);
    $this->model = FakeStructuredOutputClient::bind();

    $this->conversation = Conversation::factory()->create();
    [$this->refundChunk, $this->hoursChunk] = ChunkFixture::create($this->conversation->document, [
        [2, REFUND_TEXT, [1, 0.1]],
        [3, HOURS_TEXT, [0.1, 1]],
    ]);
});

/** Adds a question and its pending answer; returns the pending answer. */
function ask(Conversation $conversation, string $question): Message
{
    Message::factory()->for($conversation)->create(['content' => $question]);

    return Message::factory()->for($conversation)->pendingAnswer()->create();
}

function runAnswerJob(Message $answer): AnswerQuestion
{
    $job = (new AnswerQuestion($answer->fresh()))->withFakeQueueInteractions();
    $job->handle(app(ChunkRetriever::class), app(AnswerPromptBuilder::class), app(StructuredOutputClient::class), app(CitationVerifier::class));

    return $job;
}

it('runs on the answers queue with retries and a timeout below retry_after', function () {
    $job = new AnswerQuestion(Message::factory()->pendingAnswer()->create());

    expect($job->queue)->toBe('answers')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 15])
        ->and($job->deleteWhenMissingModels)->toBeTrue()
        ->and($job->uniqueId())->toBe($job->answer->id)
        ->and(config('services.openai.timeout') + config('services.openai.answer_timeout'))->toBeLessThan($job->timeout)
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout);
});

it('answers with verified citations and records cost and timing', function () {
    $this->model->reply([
        'answer_found' => true,
        'answer' => 'You can return unused items within 30 days [S1].',
        'citations' => [['source' => 'S1', 'quote' => 'return any unused item within 30 days of purchase']],
    ]);
    $answer = ask($this->conversation, 'What is the refund policy?');
    $before = $this->conversation->updated_at;
    $this->travel(1)->minutes();

    runAnswerJob($answer);

    $answer->refresh();
    expect($answer->status)->toBe(MessageStatus::Completed)
        ->and($answer->content)->toBe('You can return unused items within 30 days [1].')
        ->and($answer->answer_found)->toBeTrue()
        ->and($answer->model)->toBe('fake-answer-model')
        ->and($answer->input_tokens)->toBe(1200)
        ->and($answer->output_tokens)->toBe(150)
        ->and($answer->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($answer->citations->map->only(['document_chunk_id', 'position', 'number', 'page_number', 'quote'])->all())->toBe([[
            'document_chunk_id' => $this->refundChunk, 'position' => 0, 'number' => 1, 'page_number' => 2,
            'quote' => 'return any unused item within 30 days of purchase',
        ]])
        ->and($this->conversation->fresh()->updated_at->isAfter($before))->toBeTrue();
});

it('sends the most relevant chunks first, with the question and document title', function () {
    $this->model->reply(['answer_found' => false, 'answer' => '', 'citations' => []]);

    runAnswerJob(ask($this->conversation, 'Can I get a refund?'));

    $input = $this->model->calls[0]['input'];
    expect(strpos($input, '<source id="S1" page="2">'))->toBeLessThan(strpos($input, '<source id="S2" page="3">'))
        ->and($input)->toContain('<question>Can I get a refund?</question>')
        ->and($input)->toContain('<document_title>'.$this->conversation->document->title.'</document_title>')
        ->and($this->model->calls[0]['instructions'])->toBe(AnswerPromptBuilder::INSTRUCTIONS);
});

it('answers "not found" without calling the model when nothing is relevant', function () {
    $answer = ask($this->conversation, 'What will the weather be tomorrow?');

    runAnswerJob($answer);

    $answer->refresh();
    expect($this->model->calls)->toBe([])
        ->and($answer->status)->toBe(MessageStatus::Completed)
        ->and($answer->answer_found)->toBeFalse()
        ->and($answer->content)->toBe(CitationVerifier::NOT_FOUND_MESSAGE)
        ->and($answer->model)->toBeNull()
        ->and($answer->citations)->toHaveCount(0);
});

it('stores "not found" instead of an answer whose citations do not hold up', function () {
    $this->model->reply([
        'answer_found' => true,
        'answer' => 'Refunds are possible for 5 years [S1].',
        'citations' => [['source' => 'S1', 'quote' => 'refunds are possible for 5 years']],
    ]);
    Log::spy();
    $answer = ask($this->conversation, 'How long do I have for a refund?');

    runAnswerJob($answer);

    expect($answer->refresh()->answer_found)->toBeFalse()
        ->and($answer->content)->toBe(CitationVerifier::NOT_FOUND_MESSAGE)
        ->and($answer->citations)->toHaveCount(0);
    Log::shouldHaveReceived('info')->withArgs(fn ($event, $context) => $event === 'Answer generated'
        && $context['downgraded'] === true && $context['rejected_citations'] === 1);
});

it('uses the previous exchange for follow-up questions', function () {
    Message::factory()->for($this->conversation)->create(['content' => 'What is the refund policy?']);
    Message::factory()->for($this->conversation)->answer()->create(['content' => 'Within 30 days [1].']);
    $answer = ask($this->conversation, 'And do I need a receipt?');
    $this->model->reply(['answer_found' => false, 'answer' => '', 'citations' => []]);

    runAnswerJob($answer);

    // The follow-up alone mentions no topic; searching with the previous question finds the refund page.
    expect(end($this->embeddings->calls))->toBe(["What is the refund policy?\nAnd do I need a receipt?"])
        ->and($this->model->calls[0]['input'])->toContain('<conversation_history>')
        ->and($this->model->calls[0]['input'])->toContain('<answer>Within 30 days [1].</answer>')
        ->and($this->model->calls[0]['input'])->toContain('<question>And do I need a receipt?</question>');
});

it('leaves answers that are no longer pending alone', function () {
    $answer = Message::factory()->for($this->conversation)->answer()->create();

    runAnswerJob($answer);

    expect($this->embeddings->calls)->toBe([])->and($this->model->calls)->toBe([]);
});

it('fails the answer when the document is not ready', function () {
    $this->conversation->document->update(['status' => DocumentStatus::Embedding]);
    $answer = ask($this->conversation, 'What is the refund policy?');

    runAnswerJob($answer);

    expect($answer->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe(AnswerQuestion::NOT_READY_CODE);
    expect($this->model->calls)->toBe([]);
});

it('fails at once on permanent OpenAI errors', function () {
    $this->model->reply(OpenAiException::quotaExceeded('HTTP 429 code=insufficient_quota'));

    $job = runAnswerJob(ask($this->conversation, 'What is the refund policy?'));

    $job->assertFailedWith(OpenAiException::class);
    $job->assertNotReleased();
});

it('shows a generic message, or a rephrase hint for refusals', function (?Throwable $exception, string $code, string $message) {
    $answer = ask($this->conversation, 'What is the refund policy?');

    (new AnswerQuestion($answer))->failed($exception);

    expect($answer->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe($code)
        ->error_message->toBe($message);
})->with([
    'quota' => [fn () => OpenAiException::quotaExceeded('HTTP 429'), AnswerQuestion::FAILED_CODE, AnswerQuestion::FAILED_MESSAGE],
    'refused' => [fn () => OpenAiException::refused('model refusal'), AnswerQuestion::REFUSED_CODE, AnswerQuestion::REFUSED_MESSAGE],
    'crash' => [fn () => new RuntimeException('SQLSTATE connection lost'), AnswerQuestion::FAILED_CODE, AnswerQuestion::FAILED_MESSAGE],
]);

it('never overwrites a finished answer when failing late', function () {
    $answer = Message::factory()->for($this->conversation)->answer()->create();

    (new AnswerQuestion($answer))->failed(new RuntimeException('late'));

    expect($answer->refresh()->status)->toBe(MessageStatus::Completed);
});

it('retries temporary failures, honouring Retry-After', function () {
    $this->model->reply(OpenAiException::rateLimited('HTTP 429', retryAfter: 12));

    $job = runAnswerJob(ask($this->conversation, 'What is the refund policy?'));

    $job->assertReleased(delay: 12);
    $job->assertNotFailed();
});

it('rethrows temporary failures without Retry-After, so the backoff applies', function () {
    $this->embeddings->failWith(OpenAiException::connectionFailed('timeout'));
    $answer = ask($this->conversation, 'What is the refund policy?');

    expect(fn () => runAnswerJob($answer))->toThrow(OpenAiException::class);
    expect($answer->refresh()->status)->toBe(MessageStatus::Pending);
});

it('stores nothing when the conversation is deleted while the model is answering', function () {
    $conversation = $this->conversation;
    $this->model->reply(function () use ($conversation) {
        $conversation->delete(); // the user deletes the conversation meanwhile

        return [
            'answer_found' => true,
            'answer' => 'Within 30 days [S1].',
            'citations' => [['source' => 'S1', 'quote' => 'return any unused item within 30 days']],
        ];
    });

    runAnswerJob(ask($conversation, 'What is the refund policy?'));

    expect(Message::count())->toBe(0)->and(MessageCitation::count())->toBe(0);
});

it('logs metrics but never the question, sources or answer', function () {
    Log::spy();
    $this->model->reply([
        'answer_found' => true,
        'answer' => 'Within 30 days [S1].',
        'citations' => [['source' => 'S1', 'quote' => 'return any unused item within 30 days']],
    ]);

    runAnswerJob(ask($this->conversation, 'What is the refund policy?'));

    Log::shouldHaveReceived('info')->withArgs(function ($event, $context) {
        $json = json_encode($context);

        return $event === 'Answer generated'
            && $context['input_tokens'] === 1200
            && $context['citations'] === 1
            && $context['pages'] === [2, 3]
            && ! str_contains($json, 'refund')
            && ! str_contains($json, 'Within 30 days');
    });
});
