<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions and answers about a document.
 *
 *   documents 1--n conversations 1--n messages 1--n message_citations
 *
 * Deleting a document deletes its conversations, messages and citations
 * (ON DELETE CASCADE), inside the same transaction as the document row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Stored for cheap ownership checks; always the document's owner.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
            $table->index(['document_id', 'updated_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            // ULIDs are generated in increasing order, so `id` gives the message order
            // even for a question and its answer created in the same second.
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // user | assistant
            $table->string('status', 20); // pending | completed | failed
            $table->text('content')->nullable(); // null while an answer is pending
            $table->boolean('answer_found')->nullable(); // assistant only
            $table->string('error_code', 50)->nullable();
            $table->string('error_message')->nullable(); // safe to show to the user
            // Cost and performance of generated answers (no text is logged elsewhere).
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create('message_citations', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('message_id')->constrained()->cascadeOnDelete();
            // The chunk the citation came from; kept null if chunks are ever rebuilt.
            $table->foreignId('document_chunk_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position'); // order within the answer
            // Copied from the chunk, so the citation stays valid on its own.
            $table->unsignedSmallInteger('page_number');
            $table->text('quote'); // verified to appear in the chunk text

            $table->unique(['message_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_citations');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
