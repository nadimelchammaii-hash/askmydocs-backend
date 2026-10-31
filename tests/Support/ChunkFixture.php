<?php

namespace Tests\Support;

use App\Models\Document;
use App\Models\DocumentChunk;

/**
 * Chunks with hand-designed embeddings, so tests know exactly which chunk is
 * most similar to a question (see FakeEmbeddingClient::vectorFor()).
 */
final class ChunkFixture
{
    /**
     * A 1536-dimension unit vector starting with the given values.
     *
     * @param  list<float|int>  $leading
     * @return list<float>
     */
    public static function vector(array $leading): array
    {
        $vector = array_pad($leading, 1536, 0.0);
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vector)));

        return array_map(fn ($x) => $x / $norm, $vector);
    }

    /**
     * @param  list<array{int, string, list<float|int>}>  $chunks  [page, content, leading vector values]
     * @return list<int> chunk ids, in the given order
     */
    public static function create(Document $document, array $chunks): array
    {
        return array_map(fn (array $chunk, int $index) => DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => $index,
            'page_number' => $chunk[0],
            'content' => $chunk[1],
            'char_count' => mb_strlen($chunk[1]),
            'embedding' => self::vector($chunk[2]),
        ])->id, $chunks, array_keys($chunks));
    }
}
