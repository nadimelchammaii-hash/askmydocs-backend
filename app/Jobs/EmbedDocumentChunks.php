<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentPage;
use App\Services\Chunking\Chunk;
use App\Services\Chunking\TextChunker;
use App\Services\Embeddings\EmbeddingClient;
use App\Services\OpenAi\OpenAiException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Splits a document's extracted pages into chunks, embeds them and stores them:
 * the last processing step (embedding -> ready).
 *
 * Failure handling:
 *  - permanent problems (missing/invalid API key, no credit, rejected request)
 *    fail at once: retrying cannot help until the configuration is fixed;
 *  - transient problems (rate limit, OpenAI outage, network) are retried after
 *    30 s, 2 min and 5 min, or after the delay OpenAI asks for (Retry-After).
 *
 * Users see one generic message for every embedding failure: the causes are
 * configuration or provider problems, not problems with their file. Details
 * are in the log.
 *
 * Timing: a 300-page document needs roughly 3-9 requests of 100 chunks, each
 * normally taking 1-3 s (the 30 s HTTP timeout only stops a hung connection).
 * This job's $timeout (240 s) is a safety net and must stay below the queue's
 * retry_after (300 s), or a running job could be handed to a second worker.
 */
class EmbedDocumentChunks implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const ERROR_CODE = 'embedding_failed';

    public const ERROR_MESSAGE = 'This document could not be prepared for questions. Please try again later.';

    public int $tries = 4;

    /** @var list<int> seconds to wait before the 2nd, 3rd and 4th attempt */
    public array $backoff = [30, 120, 300];

    public int $timeout = 240;

    public bool $deleteWhenMissingModels = true;

    public int $uniqueFor = 1800;

    public function __construct(public Document $document)
    {
        $this->onQueue('documents');
    }

    public function uniqueId(): string
    {
        return $this->document->id;
    }

    public function handle(TextChunker $chunker, EmbeddingClient $embeddings): void
    {
        $document = $this->document;

        if ($document->status !== DocumentStatus::Embedding) {
            return; // already done, failed, deleted or re-queued
        }

        $pages = DocumentPage::where('document_id', $document->id)->pluck('text', 'page_number')->all();
        $chunks = $chunker->chunk($pages);

        if ($chunks === []) {
            // Extraction guarantees text, so this means the pages were lost.
            $this->fail(new \RuntimeException('Document has no text to embed.'));

            return;
        }

        try {
            $result = $embeddings->embed(array_map(
                fn (Chunk $chunk): string => $this->embeddingInput($document, $chunk),
                $chunks,
            ));
        } catch (OpenAiException $exception) {
            Log::warning('Document embedding failed', [
                'document_id' => $document->id,
                'reason' => $exception->reason,
                'detail' => $exception->detail,
                'attempt' => $this->attempts(),
                'permanent' => $exception->isPermanent(),
            ]);

            if ($exception->isPermanent()) {
                $this->fail($exception);
            } elseif ($exception->retryAfter !== null) {
                $this->release($exception->retryAfter); // the delay OpenAI asked for
            } else {
                throw $exception; // retried after $backoff
            }

            return;
        }

        $stored = $this->storeChunks($chunks, $result->vectors, $embeddings->model());

        if ($stored) {
            // Cost visibility; never any text.
            Log::info('Document embedded', [
                'document_id' => $document->id,
                'chunks' => count($chunks),
                'tokens' => $result->totalTokens,
                'model' => $result->model,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Document::whereKey($this->document->id)
            ->where('status', '!=', DocumentStatus::Ready)
            ->update([
                'status' => DocumentStatus::Failed,
                'error_code' => self::ERROR_CODE,
                'error_message' => self::ERROR_MESSAGE,
            ]);
    }

    /**
     * The text sent for embedding: the chunk with a short header naming the
     * document and page. The header helps search match questions such as
     * "what does the 2025 report say about...", and is not stored.
     */
    private function embeddingInput(Document $document, Chunk $chunk): string
    {
        return "Document: {$document->title}\nPage {$chunk->pageNumber}\n\n{$chunk->content}";
    }

    /**
     * Replaces the document's chunks and marks it ready, atomically. The row is
     * locked first, so a concurrent delete or retry cannot interleave.
     *
     * @param  list<Chunk>  $chunks
     * @param  list<list<float>>  $vectors
     */
    private function storeChunks(array $chunks, array $vectors, string $model): bool
    {
        return DB::transaction(function () use ($chunks, $vectors, $model): bool {
            $document = Document::whereKey($this->document->id)->lockForUpdate()->first();

            if ($document === null || $document->status !== DocumentStatus::Embedding) {
                return false; // deleted or changed while embedding
            }

            DocumentChunk::where('document_id', $document->id)->delete();

            $rows = array_map(fn (Chunk $chunk, array $vector): array => [
                'document_id' => $document->id,
                'chunk_index' => $chunk->index,
                'page_number' => $chunk->pageNumber,
                'content' => $chunk->content,
                'char_count' => $chunk->charCount(),
                'embedding' => json_encode($vector, JSON_THROW_ON_ERROR), // pgvector text format: [x,y,...]
            ], $chunks, $vectors);

            foreach (array_chunk($rows, 100) as $batch) {
                DocumentChunk::insert($batch);
            }

            $document->update([
                'status' => DocumentStatus::Ready,
                'embedding_model' => $model,
                'processed_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);

            return true;
        });
    }
}
