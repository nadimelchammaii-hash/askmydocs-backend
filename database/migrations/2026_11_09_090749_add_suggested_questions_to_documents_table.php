<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat suggestions written for one document: set on the demo samples (DemoSeeder,
 * from config('demo.suggested_questions')) and copied with them. Null for uploads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->json('suggested_questions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('suggested_questions');
        });
    }
};
