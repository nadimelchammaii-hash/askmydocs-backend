<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Searchable pieces of a document's text, each with its embedding.
 *
 * The vector size is fixed here (1536 = text-embedding-3-small) and must match
 * OPENAI_EMBEDDING_DIMENSIONS. Switching to a model with another size needs a
 * new migration and re-embedding every document: vectors from different models
 * cannot be compared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->unsignedSmallInteger('page_number');
            $table->text('content');
            $table->unsignedInteger('char_count');
            $table->vector('embedding', dimensions: 1536);

            $table->unique(['document_id', 'chunk_index']);
            // Approximate nearest-neighbour search by cosine distance (HNSW, vector_cosine_ops).
            $table->vectorIndex('embedding');
        });

        Schema::table('documents', function (Blueprint $table) {
            // Which model produced this document's embeddings (null until embedded).
            $table->string('embedding_model', 100)->nullable()->after('page_count');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('embedding_model');
        });

        Schema::dropIfExists('document_chunks');
    }
};
