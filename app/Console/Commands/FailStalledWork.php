<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Document;
use App\Models\Message;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ends work that will not finish on its own, e.g. because no queue worker is
 * running or a job was lost: without this, an answer stays "pending" forever
 * (and its conversation refuses new questions) and a document stays processing.
 *
 * Both become failed with a message that invites a retry. If the job runs later
 * anyway, it sees the failed status and does nothing (no OpenAI call).
 * Scheduled every five minutes (routes/console.php).
 */
#[Signature('work:fail-stalled')]
#[Description('Mark answers and documents that have been processing for too long as failed')]
class FailStalledWork extends Command
{
    public const ANSWER_CODE = 'answer_failed';

    public const ANSWER_MESSAGE = 'The answer took too long. Please try again.';

    public const DOCUMENT_CODE = 'processing_stalled';

    public const DOCUMENT_MESSAGE = 'Processing did not finish. Please try again.';

    public function handle(): int
    {
        // Conditional updates: anything that finished meanwhile is left alone.
        $answers = Message::where('role', MessageRole::Assistant)
            ->where('status', MessageStatus::Pending)
            ->where('updated_at', '<', now()->subMinutes((int) config('answers.stalled_after_minutes')))
            ->update([
                'status' => MessageStatus::Failed,
                'error_code' => self::ANSWER_CODE,
                'error_message' => self::ANSWER_MESSAGE,
            ]);

        $documents = Document::whereNotIn('status', [DocumentStatus::Ready, DocumentStatus::Failed])
            ->where('updated_at', '<', now()->subMinutes((int) config('documents.stalled_after_minutes')))
            ->update([
                'status' => DocumentStatus::Failed,
                'error_code' => self::DOCUMENT_CODE,
                'error_message' => self::DOCUMENT_MESSAGE,
            ]);

        if ($answers > 0 || $documents > 0) {
            Log::warning('Stalled work marked as failed; are the queue workers running?', [
                'answers' => $answers,
                'documents' => $documents,
            ]);
        }

        $this->info("Failed {$answers} stalled answer(s) and {$documents} stalled document(s).");

        return self::SUCCESS;
    }
}
