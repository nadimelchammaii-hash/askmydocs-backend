<?php

namespace App\Actions\Conversations;

use App\Enums\DocumentStatus;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Exceptions\ApiException;
use App\Jobs\AnswerQuestion;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores a question with a pending answer and queues the answer job.
 *
 * Rules: the document must be ready, and a conversation has at most one
 * pending answer at a time (the conversation row is locked while checking,
 * so two simultaneous requests cannot both pass).
 */
class AskQuestion
{
    /**
     * Starts a new conversation about a document with its first question.
     */
    public function start(User $user, Document $document, string $question): Conversation
    {
        $this->ensureReady($document);

        $conversation = DB::transaction(function () use ($user, $document, $question) {
            $conversation = Conversation::create([
                'user_id' => $user->id,
                'document_id' => $document->id,
                'title' => Str::limit(preg_replace('/\s+/u', ' ', trim($question)), 80, '…', preserveWords: true),
            ]);

            $this->storeMessages($conversation, $question);

            return $conversation;
        });

        return $conversation->load('messages.citations');
    }

    /**
     * Adds a follow-up question to an existing conversation.
     *
     * @return array{Message, Message} [question, pending answer]
     */
    public function followUp(Conversation $conversation, string $question): array
    {
        $this->ensureReady($conversation->document);

        return DB::transaction(function () use ($conversation, $question) {
            Conversation::whereKey($conversation->id)->lockForUpdate()->first();

            $pending = $conversation->messages()->where('status', MessageStatus::Pending)->exists();
            if ($pending) {
                throw ApiException::conflict('answer_pending', 'Wait for the current answer before asking another question.');
            }

            $messages = $this->storeMessages($conversation, $question);
            $conversation->touch();

            return $messages;
        });
    }

    /**
     * Asks again for a failed answer, with the same question.
     */
    public function retryAnswer(Conversation $conversation, Message $answer): Message
    {
        $this->ensureReady($conversation->document);

        return DB::transaction(function () use ($conversation, $answer) {
            Conversation::whereKey($conversation->id)->lockForUpdate()->first();
            $answer = Message::whereKey($answer->id)->lockForUpdate()->firstOrFail();

            if ($answer->role !== MessageRole::Assistant || $answer->status !== MessageStatus::Failed) {
                throw ApiException::conflict('answer_not_failed', 'Only failed answers can be retried.');
            }

            if ($conversation->messages()->where('status', MessageStatus::Pending)->exists()) {
                throw ApiException::conflict('answer_pending', 'Wait for the current answer before retrying.');
            }

            $answer->update(['status' => MessageStatus::Pending, 'error_code' => null, 'error_message' => null]);
            $conversation->touch();

            AnswerQuestion::dispatch($answer)->afterCommit();

            return $answer;
        });
    }

    /**
     * @return array{Message, Message}
     */
    private function storeMessages(Conversation $conversation, string $question): array
    {
        $questionMessage = $conversation->messages()->create([
            'role' => MessageRole::User,
            'status' => MessageStatus::Completed,
            'content' => trim($question),
        ]);

        $answer = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Pending,
        ]);

        // Only once the transaction has committed, so the worker can see the rows.
        AnswerQuestion::dispatch($answer)->afterCommit();

        return [$questionMessage, $answer];
    }

    private function ensureReady(Document $document): void
    {
        if ($document->status !== DocumentStatus::Ready) {
            throw ApiException::conflict('document_not_ready', 'This document is not ready for questions yet.');
        }
    }
}
