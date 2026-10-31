<?php

namespace App\Services\Retrieval;

use App\Models\DocumentChunk;
use App\Services\Embeddings\EmbeddingClient;
use App\Services\Embeddings\EmbeddingException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Finds the chunks most relevant to a question (semantic search).
 *
 * The question is embedded with the same model as the chunks, and chunks are
 * ranked by cosine distance using the HNSW index.
 *
 * Filtering pitfall: HNSW first finds the nearest chunks across ALL documents
 * (40 by default) and filters by document afterwards. When other documents'
 * chunks are closer, a filtered search could return too few or no results.
 * pgvector 0.8's iterative scan keeps scanning until enough rows pass the
 * filter; it is enabled for this query only (SET LOCAL inside a transaction).
 */
class ChunkRetriever
{
    public const MAX_LIMIT = 20;

    public function __construct(private readonly EmbeddingClient $embeddings) {}

    /**
     * @param  list<string>  $documentIds  documents the caller is allowed to search
     * @return list<RetrievedChunk> most similar first
     *
     * @throws EmbeddingException when the question cannot be embedded
     */
    public function search(array $documentIds, string $question, int $limit = 5): array
    {
        $question = trim($question);

        if ($question === '') {
            throw new InvalidArgumentException('The question must not be empty.');
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('The limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        if ($documentIds === []) {
            return []; // nothing to search: do not pay for an embedding
        }

        $vector = $this->embeddings->embed([$question])->vectors[0];

        $rows = DB::transaction(function () use ($documentIds, $vector, $limit) {
            // relaxed_order gives the best recall; exact order is restored below.
            DB::statement('set local hnsw.iterative_scan = relaxed_order');

            return DocumentChunk::query()
                ->select(['id', 'document_id', 'chunk_index', 'page_number', 'content'])
                ->selectVectorDistance('embedding', $vector, as: 'distance')
                ->whereIn('document_id', $documentIds)
                ->orderByVectorDistance('embedding', $vector)
                ->limit($limit)
                ->toBase()
                ->get();
        });

        return $rows
            ->sortBy('distance')
            ->map(fn (object $row) => new RetrievedChunk(
                chunkId: (int) $row->id,
                documentId: $row->document_id,
                pageNumber: (int) $row->page_number,
                chunkIndex: (int) $row->chunk_index,
                content: $row->content,
                similarity: round(1 - (float) $row->distance, 6),
            ))
            ->values()
            ->all();
    }
}
