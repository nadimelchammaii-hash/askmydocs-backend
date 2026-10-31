<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\OpenAi\OpenAiException;
use App\Services\Retrieval\ChunkRetriever;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Developer tool: shows which chunks a question retrieves from a document.
 * Embedding the question calls OpenAI (a tiny cost per run).
 */
#[Signature('documents:search
    {document : Document ID}
    {question : The question to search for}
    {--limit=5 : Number of chunks to show}')]
#[Description('Show the chunks of a document that are most relevant to a question (calls OpenAI)')]
class SearchDocument extends Command
{
    public function handle(ChunkRetriever $retriever): int
    {
        $document = Document::find($this->argument('document'));

        if ($document === null) {
            $this->error('Document not found.');

            return self::FAILURE;
        }

        if ($document->status !== DocumentStatus::Ready) {
            $this->error("Document is not ready (status: {$document->status->value}).");

            return self::FAILURE;
        }

        try {
            $chunks = $retriever->search([$document->id], (string) $this->argument('question'), (int) $this->option('limit'));
        } catch (OpenAiException $exception) {
            $this->error("Could not embed the question: {$exception->getMessage()} ({$exception->reason})");

            return self::FAILURE;
        }

        $this->info("{$document->title} ({$document->embedding_model})");
        $this->table(
            ['#', 'Page', 'Similarity', 'Excerpt'],
            array_map(fn ($chunk, int $rank) => [
                $rank + 1,
                $chunk->pageNumber,
                number_format($chunk->similarity, 3),
                Str::limit(preg_replace('/\s+/', ' ', $chunk->content), 100),
            ], $chunks, array_keys($chunks)),
        );

        return self::SUCCESS;
    }
}
