<?php

namespace App\Services\Retrieval;

use App\Models\DocumentChunk;
use App\Services\Embeddings\EmbeddingClient;
use App\Services\OpenAi\OpenAiException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Finds the chunks most relevant to a question (semantic search).
 *
 * The question is embedded with the same model as the chunks, and the chunks of
 * the given documents are ranked by cosine distance.
 *
 * The search is EXACT: every chunk of the given documents is compared. The HNSW
 * index is approximate: it walks a graph of chunks across all documents and can
 * miss poorly connected ones, so a filtered search sometimes returned fewer
 * chunks than exist (or none), at random. A search always covers a few known
 * documents (a few thousand chunks at most), so comparing them all takes
 * milliseconds and never misses a page.
 */
class ChunkRetriever
{
    public const MAX_LIMIT = 20;

    public function __construct(private readonly EmbeddingClient $embeddings) {}

    /**
     * @param  list<string>  $documentIds  documents the caller is allowed to search
     * @return list<RetrievedChunk> most similar first
     *
     * @throws OpenAiException when the question cannot be embedded
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

        // The chunks of these documents (found through the document_id index) with their
        // distance. OFFSET 0 keeps PostgreSQL from merging this into the outer query, where
        // "order by distance" would let it use the approximate HNSW index instead.
        $candidates = DocumentChunk::query()
            ->select(['id', 'document_id', 'chunk_index', 'page_number', 'content'])
            ->selectVectorDistance('embedding', $vector, as: 'distance')
            ->whereIn('document_id', $documentIds)
            ->offset(0)
            ->toBase();

        $rows = DB::query()
            ->fromSub($candidates, 'candidates')
            ->orderBy('distance')
            ->orderBy('id') // equal distances: always the same order
            ->limit($limit)
            ->get();

        return $rows
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
