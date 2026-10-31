<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\MessageCitation;
use App\Services\Answering\AnswerPromptBuilder;
use App\Services\Answering\CitationVerifier;
use App\Services\Answering\VerifiedAnswer;
use App\Services\Answering\VerifiedCitation;
use App\Services\OpenAi\OpenAiException;
use App\Services\OpenAi\StructuredOutputClient;
use App\Services\Retrieval\ChunkRetriever;
use App\Services\Retrieval\RetrievedChunk;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Answers the question that precedes a pending assistant message:
 *
 *   1. search the document (current question + previous question, for follow-ups);
 *   2. if nothing is relevant enough, answer "not found" without calling the model;
 *   3. otherwise ask the model, keep only citations whose quote is in the cited
 *      source, and store the answer with its citations atomically.
 *
 * Failures follow the OpenAI rules: permanent ones fail at once, temporary ones
 * are retried (5 s, 15 s, or OpenAI's Retry-After). The user sees a generic message.
 *
 * Timing: question embedding (30 s max) + answer (60 s max) < $timeout (120 s)
 * < the queue's retry_after (300 s).
 */
class AnswerQuestion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const FAILED_CODE = 'answer_failed';

    public const FAILED_MESSAGE = 'The answer could not be generated. Please try again.';

    public const REFUSED_CODE = 'answer_refused';

    public const REFUSED_MESSAGE = 'This question could not be answered. Please rephrase it.';

    public const NOT_READY_CODE = 'document_not_ready';

    public const NOT_READY_MESSAGE = 'The document is being processed. Ask again when it is ready.';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15];

    public int $timeout = 120;

    /** The conversation (or its document) was deleted: nothing to answer. */
    public bool $deleteWhenMissingModels = true;

    public int $uniqueFor = 600;

    public function __construct(public Message $answer)
    {
        $this->onQueue('answers');
    }

    public function uniqueId(): string
    {
        return $this->answer->id;
    }

    public function handle(
        ChunkRetriever $retriever,
        AnswerPromptBuilder $prompts,
        StructuredOutputClient $model,
        CitationVerifier $verifier,
    ): void {
        $started = hrtime(true);
        $answer = $this->answer;

        if ($answer->status !== MessageStatus::Pending || $answer->role !== MessageRole::Assistant) {
            return;
        }

        $conversation = $answer->conversation;
        $document = $conversation->document;

        if ($document->status !== DocumentStatus::Ready) {
            $this->complete(failure: [self::NOT_READY_CODE, self::NOT_READY_MESSAGE]);

            return;
        }

        $earlier = $conversation->messages()->where('id', '<', $answer->id)->get();
        $question = $earlier->last();

        if ($question === null || $question->role !== MessageRole::User) {
            $this->fail(new RuntimeException('No question precedes this answer.'));

            return;
        }

        $history = $this->history($earlier->slice(0, -1)->values()->all());

        try {
            // A follow-up like "and for refunds?" needs the previous question to be found.
            $searchText = trim(($history === [] ? '' : end($history)['question']."\n").$question->content);
            $chunks = $retriever->search([$document->id], $searchText, (int) config('answers.top_k'));
            $topSimilarity = $chunks[0]->similarity ?? 0.0;

            if ($topSimilarity < (float) config('answers.min_similarity')) {
                $this->complete(new VerifiedAnswer(false, CitationVerifier::NOT_FOUND_MESSAGE, [], 0, false), $started);
                $this->log('Answer skipped: nothing relevant', ['top_similarity' => $topSimilarity, 'chunks' => count($chunks)], $started);

                return;
            }

            $prompt = $prompts->build($question->content, $chunks, $document->title, $history);
            $output = $model->generate($prompt->instructions, $prompt->input, $prompt->schema);
            $verified = $verifier->verify($output->data, $prompt->sources);
        } catch (OpenAiException $exception) {
            $this->handleOpenAiFailure($exception);

            return;
        }

        if ($this->complete($verified, $started, $output->model, $output->inputTokens, $output->outputTokens)) {
            $this->log('Answer generated', [
                'top_similarity' => $topSimilarity,
                'chunks' => count($chunks),
                'pages' => array_values(array_unique(array_map(fn (RetrievedChunk $c) => $c->pageNumber, $chunks))),
                'model' => $output->model,
                'input_tokens' => $output->inputTokens,
                'output_tokens' => $output->outputTokens,
                'answer_found' => $verified->answerFound,
                'citations' => count($verified->citations),
                'rejected_citations' => $verified->rejectedCitations,
                'downgraded' => $verified->downgraded,
            ], $started);
        }
    }

    public function failed(?Throwable $exception): void
    {
        [$code, $message] = $exception instanceof OpenAiException && $exception->reason === OpenAiException::REFUSED
            ? [self::REFUSED_CODE, self::REFUSED_MESSAGE]
            : [self::FAILED_CODE, self::FAILED_MESSAGE];

        Message::whereKey($this->answer->id)
            ->where('status', MessageStatus::Pending)
            ->update(['status' => MessageStatus::Failed, 'error_code' => $code, 'error_message' => $message]);
    }

    private function handleOpenAiFailure(OpenAiException $exception): void
    {
        Log::warning('Answer generation failed', [
            'message_id' => $this->answer->id,
            'reason' => $exception->reason,
            'detail' => $exception->detail,
            'attempt' => $this->attempts(),
            'permanent' => $exception->isPermanent(),
        ]);

        if ($exception->isPermanent()) {
            $this->fail($exception);
        } elseif ($exception->retryAfter !== null) {
            $this->release($exception->retryAfter);
        } else {
            throw $exception;
        }
    }

    /**
     * Stores the outcome on the pending message (and its citations) in one
     * transaction. Returns false if the message is gone or already finished,
     * e.g. the conversation was deleted while the model was answering.
     *
     * @param  array{string, string}|null  $failure  [error_code, error_message]
     */
    private function complete(
        ?VerifiedAnswer $verified = null,
        ?int $started = null,
        ?string $model = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?array $failure = null,
    ): bool {
        return DB::transaction(function () use ($verified, $started, $model, $inputTokens, $outputTokens, $failure): bool {
            $message = Message::whereKey($this->answer->id)->lockForUpdate()->first();

            if ($message === null || $message->status !== MessageStatus::Pending) {
                return false;
            }

            if ($failure !== null) {
                $message->update(['status' => MessageStatus::Failed, 'error_code' => $failure[0], 'error_message' => $failure[1]]);

                return true;
            }

            $message->update([
                'status' => MessageStatus::Completed,
                'content' => $verified->text,
                'answer_found' => $verified->answerFound,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'duration_ms' => $started === null ? null : (int) round((hrtime(true) - $started) / 1_000_000),
            ]);

            MessageCitation::insert(array_map(fn (VerifiedCitation $citation, int $position) => [
                'message_id' => $message->id,
                'document_chunk_id' => $citation->chunkId,
                'position' => $position,
                'page_number' => $citation->pageNumber,
                'quote' => $citation->quote,
            ], $verified->citations, array_keys($verified->citations)));

            $message->conversation()->touch(); // most recently active conversations first

            return true;
        });
    }

    /**
     * Completed question/answer pairs before the current question, oldest first.
     *
     * @param  list<Message>  $messages
     * @return list<array{question: string, answer: string}>
     */
    private function history(array $messages): array
    {
        $pairs = [];

        foreach ($messages as $index => $message) {
            $next = $messages[$index + 1] ?? null;

            if ($message->role === MessageRole::User && $next?->role === MessageRole::Assistant
                && $next->status === MessageStatus::Completed && $next->content !== null) {
                $pairs[] = ['question' => (string) $message->content, 'answer' => $next->content];
            }
        }

        return $pairs;
    }

    /**
     * Metrics only: never the question, the sources or the answer.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, array $context, int $started): void
    {
        Log::info($event, [
            'message_id' => $this->answer->id,
            'conversation_id' => $this->answer->conversation_id,
            ...$context,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
