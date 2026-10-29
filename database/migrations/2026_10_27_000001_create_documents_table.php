<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            // ULID: not guessable or enumerable like 1, 2, 3, and sortable by creation time.
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('original_filename');
            // Generated path on the private documents disk; never derived from the user's filename.
            $table->string('storage_path')->unique();
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedSmallInteger('page_count')->nullable();

            $table->string('status', 20)->default('queued');
            // Machine-readable reason (e.g. "no_text_layer") and a message that is safe to show users.
            $table->string('error_code', 50)->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            // The document library lists one user's documents, newest first or filtered by status.
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
