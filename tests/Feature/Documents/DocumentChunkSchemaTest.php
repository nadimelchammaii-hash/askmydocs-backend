<?php

use App\Actions\Documents\DeleteDocument;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use Database\Factories\DocumentChunkFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('stores an embedding and reads it back as floats', function () {
    $vector = DocumentChunkFactory::randomUnitVector();
    $chunk = DocumentChunk::factory()->create(['embedding' => $vector]);

    $stored = $chunk->fresh()->embedding;

    expect($stored)->toHaveCount(1536)
        ->and($stored[0])->toBeFloat()
        // pgvector stores 32-bit floats: equal up to float32 precision.
        ->and(abs($stored[0] - $vector[0]))->toBeLessThan(1e-6)
        ->and(abs($stored[1535] - $vector[1535]))->toBeLessThan(1e-6);
});

it('rejects embeddings of the wrong size', function () {
    DocumentChunk::factory()->create(['embedding' => DocumentChunkFactory::randomUnitVector(3072)]);
})->throws(QueryException::class, 'expected 1536 dimensions');

it('keeps chunk positions unique per document', function () {
    $document = Document::factory()->ready()->create();
    DocumentChunk::factory()->for($document)->create(['chunk_index' => 0]);

    expect(fn () => DocumentChunk::factory()->for($document)->create(['chunk_index' => 0]))
        ->toThrow(QueryException::class);
});

it('never includes the embedding when a chunk is serialized', function () {
    $chunk = DocumentChunk::factory()->create();

    expect($chunk->toArray())->not->toHaveKey('embedding');
});

it('removes chunks together with their document', function () {
    Storage::fake(config('documents.disk'));
    $document = Document::factory()->ready()->create();
    DocumentChunk::factory()->for($document)->count(3)->sequence(
        ['chunk_index' => 0], ['chunk_index' => 1], ['chunk_index' => 2],
    )->create();
    $other = DocumentChunk::factory()->create();

    app(DeleteDocument::class)->handle($document);

    expect(DocumentChunk::pluck('id')->all())->toBe([$other->id]);
});

it('orders a document\'s chunks by position', function () {
    $document = Document::factory()->ready()->create();
    DocumentChunk::factory()->for($document)->create(['chunk_index' => 1]);
    DocumentChunk::factory()->for($document)->create(['chunk_index' => 0]);

    expect($document->chunks->pluck('chunk_index')->all())->toBe([0, 1]);
});

it('has a vector column sized for the configured embedding model', function () {
    $type = DB::scalar("select format_type(atttypid, atttypmod) from pg_attribute
        where attrelid = 'document_chunks'::regclass and attname = 'embedding'");

    expect($type)->toBe('vector('.config('services.openai.embedding_dimensions').')');
});

it('has an HNSW index for cosine distance', function () {
    $definition = DB::scalar("select indexdef from pg_indexes
        where tablename = 'document_chunks' and indexdef like '%hnsw%'");

    expect($definition)->toContain('USING hnsw (embedding vector_cosine_ops)');
});

it('uses the HNSW index for nearest-neighbour search', function () {
    $document = Document::factory()->ready()->create();
    foreach (range(0, 49) as $index) {
        DocumentChunk::factory()->for($document)->create(['chunk_index' => $index]);
    }

    // With few rows PostgreSQL would rightly prefer a sequential scan; disable it
    // (for this transaction only) to prove the index can serve the query.
    DB::statement('set local enable_seqscan = off');
    $query = DocumentChunk::query()
        ->orderByVectorDistance('embedding', DocumentChunkFactory::randomUnitVector())
        ->limit(5);
    $plan = collect(DB::select('explain '.$query->toSql(), $query->getBindings()))
        ->pluck('QUERY PLAN')->implode("\n");

    expect($plan)->toContain('Index Scan using document_chunks_embedding_vectorindex');
});

it('treats embedding as a step that is still in progress', function () {
    expect(DocumentStatus::Embedding->isFinished())->toBeFalse()
        ->and(DocumentStatus::from('embedding'))->toBe(DocumentStatus::Embedding);
});
