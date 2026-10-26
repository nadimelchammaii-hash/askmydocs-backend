<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables pgvector, which adds the `vector` column type and distance operators
 * (e.g. `<=>` for cosine distance) used for semantic search over document chunks.
 *
 * The extension binaries ship with the pgvector/pgvector Docker image; this only
 * activates them in the current database. Fails if the server lacks pgvector.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
