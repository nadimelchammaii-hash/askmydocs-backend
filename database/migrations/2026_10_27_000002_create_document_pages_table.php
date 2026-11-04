<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extracted text, one row per PDF page. Kept separately from chunks so that:
 *  - citations can show the full page,
 *  - "summarize page 20" can load a page directly,
 *  - chunking can be redone without re-parsing the PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page_number');
            $table->text('text');
            $table->unsignedInteger('char_count');

            // Also serves lookups by document (leading column).
            $table->unique(['document_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_pages');
    }
};
