<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\OpenAi\OpenAiException;
use App\Services\Retrieval\ChunkRetriever;
use App\Services\Retrieval\RetrievedChunk;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeEmbeddingClient;

const QUESTION = 'What is the refund policy?';

beforeEach(function () {
    // The question points exactly along the first axis.
    $this->embeddings = FakeEmbeddingClient::bind()->vectorFor(QUESTION, [1]);
});

/** A 1536-dimension unit vector starting with the given values. */
function unitVector(array $leading): array
{
    $vector = array_pad($leading, 1536, 0.0);
    $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vector)));

    return array_map(fn ($x) => $x / $norm, $vector);
}

/** Creates chunks of a document; each item is [page, content, leading vector values]. */
function chunksFor(Document $document, array $chunks): void
{
    DocumentChunk::insert(array_map(fn (array $chunk, int $index) => [
        'document_id' => $document->id,
        'chunk_index' => $index,
        'page_number' => $chunk[0],
        'content' => $chunk[1],
        'char_count' => mb_strlen($chunk[1]),
        'embedding' => json_encode(unitVector($chunk[2])),
    ], $chunks, array_keys($chunks)));
}

function retriever(): ChunkRetriever
{
    return app(ChunkRetriever::class);
}

it('returns the most similar chunks first, with page and similarity', function () {
    $document = Document::factory()->ready()->create();
    chunksFor($document, [
        [1, 'Company history.', [0, 1]],                  // unrelated (similarity 0)
        [4, 'Refunds within 30 days.', [1, 0.1]],         // very close
        [7, 'Returns need a receipt.', [1, 1]],           // somewhat close
        [9, 'Opening hours.', [-1, 0.2]],                 // opposite
    ]);

    $results = retriever()->search([$document->id], QUESTION, limit: 3);

    expect(array_map(fn (RetrievedChunk $r) => [$r->pageNumber, $r->content], $results))->toBe([
        [4, 'Refunds within 30 days.'],
        [7, 'Returns need a receipt.'],
        [1, 'Company history.'],
    ])
        ->and($results[0]->similarity)->toEqualWithDelta(1 / sqrt(1.01), 1e-5) // cos = 0.995
        ->and($results[1]->similarity)->toEqualWithDelta(1 / sqrt(2), 1e-5)    // cos = 0.707
        ->and($results[2]->similarity)->toEqualWithDelta(0.0, 1e-5)
        ->and($results[0]->documentId)->toBe($document->id);
});

it('only searches the given documents', function () {
    $mine = Document::factory()->ready()->create();
    $someoneElses = Document::factory()->ready()->create();
    chunksFor($mine, [[2, 'My refund terms.', [1, 1]]]);
    chunksFor($someoneElses, [[1, 'A perfect match in another document.', [1]]]);

    $results = retriever()->search([$mine->id], QUESTION);

    expect(array_map(fn (RetrievedChunk $r) => $r->content, $results))->toBe(['My refund terms.']);
});

it('searches several documents at once', function () {
    $a = Document::factory()->ready()->create();
    $b = Document::factory()->ready()->create();
    chunksFor($a, [[1, 'From A.', [1, 1]]]);
    chunksFor($b, [[3, 'From B.', [1, 0.2]]]);

    $results = retriever()->search([$a->id, $b->id], QUESTION);

    expect(array_map(fn (RetrievedChunk $r) => [$r->documentId, $r->pageNumber], $results))
        ->toBe([[$b->id, 3], [$a->id, 1]]);
});

it('finds a small document\'s chunks even when many other chunks are closer', function () {
    // Without pgvector's iterative scan, the HNSW index returns only the ~40 nearest
    // chunks overall (all from the big document); filtering them leaves nothing.
    $small = Document::factory()->ready()->create();
    $big = Document::factory()->ready()->create();
    chunksFor($big, array_map(fn (int $i) => [1, "Close chunk {$i}.", [1, 0.01 * ($i % 7), 0.01 * ($i % 11)]], range(0, 299)));
    chunksFor($small, [[5, 'Far away A.', [0, 1]], [6, 'Far away B.', [0.1, 1]], [7, 'Far away C.', [-0.1, 1]]]);

    // Make PostgreSQL use the HNSW index, as it would on a large table.
    DB::statement('set local enable_seqscan = off');
    DB::statement('set local enable_bitmapscan = off');
    DB::statement('set local enable_sort = off');

    $results = retriever()->search([$small->id], QUESTION, limit: 3);

    expect(array_map(fn (RetrievedChunk $r) => $r->pageNumber, $results))->toEqualCanonicalizing([5, 6, 7]);
});

it('embeds only the question, as plain text', function () {
    $document = Document::factory()->ready()->create();
    chunksFor($document, [[1, 'Anything.', [1]]]);

    retriever()->search([$document->id], '  '.QUESTION.'  ');

    expect($this->embeddings->calls)->toBe([[QUESTION]]);
});

it('does not call OpenAI when there is nothing to search', function () {
    expect(retriever()->search([], QUESTION))->toBe([])
        ->and($this->embeddings->calls)->toBe([]);
});

it('rejects an empty question or an unreasonable limit', function (string $question, int $limit) {
    retriever()->search(['01m661xjyep8kr1dxj5pascncn'], $question, $limit);
})->throws(InvalidArgumentException::class)->with([
    'empty question' => ['   ', 5],
    'zero results' => [QUESTION, 0],
    'too many results' => [QUESTION, 21],
]);

it('lets the caller handle OpenAI failures', function () {
    $this->embeddings->failWith(OpenAiException::rateLimited('HTTP 429', 5));

    retriever()->search(['01m661xjyep8kr1dxj5pascncn'], QUESTION);
})->throws(OpenAiException::class);

describe('documents:search command', function () {
    it('prints the best chunks with page and similarity', function () {
        $document = Document::factory()->ready()->create(['title' => 'Store Policy', 'embedding_model' => 'text-embedding-3-small']);
        chunksFor($document, [[4, 'Refunds within 30 days.', [1, 0.1]], [1, 'Company history.', [0, 1]]]);

        $this->artisan('documents:search', ['document' => $document->id, 'question' => QUESTION, '--limit' => 1])
            ->expectsOutput('Store Policy (text-embedding-3-small)')
            ->expectsTable(['#', 'Page', 'Similarity', 'Excerpt'], [[1, 4, '0.995', 'Refunds within 30 days.']])
            ->assertSuccessful();
    });

    it('refuses documents that are not ready', function () {
        $document = Document::factory()->create();

        $this->artisan('documents:search', ['document' => $document->id, 'question' => QUESTION])
            ->expectsOutput('Document is not ready (status: queued).')
            ->assertFailed();
    });

    it('explains embedding failures', function () {
        $this->embeddings->failWith(OpenAiException::notConfigured());
        $document = Document::factory()->ready()->create();

        $this->artisan('documents:search', ['document' => $document->id, 'question' => QUESTION])
            ->expectsOutputToContain('No OpenAI API key is configured')
            ->assertFailed();
    });
});
