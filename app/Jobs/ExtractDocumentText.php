<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Services\Pdf\PdfExtractionException;
use App\Services\Pdf\PdfTextExtractor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extracts the text of every page of an uploaded PDF into document_pages.
 *
 * Failure handling:
 *  - permanent problems with the file (encrypted, damaged, no text layer) fail
 *    the job at once: retrying cannot help;
 *  - transient problems (timeout, unexpected tool error) are retried with a
 *    backoff; after the last attempt, failed() marks the document as failed.
 *
 * Safe to run more than once: pages are replaced as a whole, in one
 * transaction, and a document that is already ready is left untouched.
 *
 * Timing chain (each must be shorter than the next):
 *   Poppler process timeout (90 s, config/documents.php)
 *   < this job's $timeout (120 s)
 *   < the queue's retry_after (180 s, config/queue.php).
 * Otherwise the queue could hand a still-running job to a second worker.
 */
class ExtractDocumentText implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds to wait before the 2nd and 3rd attempt */
    public array $backoff = [10, 60];

    public int $timeout = 120;

    /** The document was deleted before the job ran: nothing to do. */
    public bool $deleteWhenMissingModels = true;

    /** Safety net: the uniqueness lock expires even if a worker dies without releasing it. */
    public int $uniqueFor = 600;

    public function __construct(public Document $document)
    {
        $this->onQueue('documents');
    }

    /** At most one queued or running extraction per document. */
    public function uniqueId(): string
    {
        return $this->document->id;
    }

    public function handle(PdfTextExtractor $extractor): void
    {
        $document = $this->document;

        // Queued: normal case. Extracting: a previous attempt crashed or timed out.
        if (! in_array($document->status, [DocumentStatus::Queued, DocumentStatus::Extracting], true)) {
            return;
        }

        $disk = Storage::disk(config('documents.disk'));

        if (! $disk->exists($document->storage_path)) {
            Log::error('Document file is missing; extraction skipped', ['document_id' => $document->id]);
            $this->markFailed('file_missing', 'The uploaded file could not be found. Please upload it again.');

            return;
        }

        $document->update([
            'status' => DocumentStatus::Extracting,
            'processing_started_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ]);

        try {
            $pages = $extractor->extractPages($disk->path($document->storage_path));
        } catch (PdfExtractionException $exception) {
            // Reason and tool output only; never the document's text.
            Log::warning('Document text extraction failed', [
                'document_id' => $document->id,
                'reason' => $exception->reason,
                'detail' => $exception->detail,
                'attempt' => $this->attempts(),
                'permanent' => $exception->isPermanent(),
            ]);

            if ($exception->isPermanent()) {
                $this->fail($exception); // no retries; calls failed()

                return;
            }

            throw $exception; // the queue retries after the backoff
        }

        $this->storePages($pages);
    }

    /**
     * Called once the job has definitely failed: a permanent error, the last
     * attempt of a transient one, or a worker crash/timeout.
     */
    public function failed(?Throwable $exception): void
    {
        $error = $exception instanceof PdfExtractionException ? $exception : PdfExtractionException::failed();

        $this->markFailed($error->reason, $error->getMessage());
    }

    /**
     * Replaces the document's pages and marks it ready, atomically.
     *
     * The row is locked first, so a concurrent DELETE either finishes before
     * (the row is gone and nothing is written) or waits until this commits
     * (and then removes the new pages via ON DELETE CASCADE).
     *
     * @param  list<string>  $pages
     */
    private function storePages(array $pages): void
    {
        DB::transaction(function () use ($pages): void {
            $document = Document::whereKey($this->document->id)->lockForUpdate()->first();

            if ($document === null) {
                return; // deleted while extracting
            }

            DocumentPage::where('document_id', $document->id)->delete();

            $rows = array_map(fn (string $text, int $index): array => [
                'document_id' => $document->id,
                'page_number' => $index + 1,
                'text' => $text,
                'char_count' => mb_strlen($text),
            ], $pages, array_keys($pages));

            foreach (array_chunk($rows, 100) as $chunk) {
                DocumentPage::insert($chunk);
            }

            $document->update([
                'status' => DocumentStatus::Ready,
                'page_count' => count($pages),
                'processed_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);
        });
    }

    private function markFailed(string $code, string $message): void
    {
        // A plain UPDATE: works even if the document was deleted meanwhile (0 rows),
        // and never turns a document that became ready back into a failed one.
        Document::whereKey($this->document->id)
            ->where('status', '!=', DocumentStatus::Ready)
            ->update([
                'status' => DocumentStatus::Failed,
                'error_code' => $code,
                'error_message' => $message,
            ]);
    }
}
