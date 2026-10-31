<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Citations are numbered per cited source: several quotes from the same
 * source share the marker number ([1]) used in the answer text, so every
 * marker points to citations and every citation is referenced by a marker.
 * `position` keeps the order of the quotes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_citations', function (Blueprint $table) {
            $table->unsignedSmallInteger('number')->nullable()->after('position');
        });

        // Existing citations were numbered one per citation.
        DB::table('message_citations')->update(['number' => DB::raw('position + 1')]);

        Schema::table('message_citations', function (Blueprint $table) {
            $table->unsignedSmallInteger('number')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('message_citations', function (Blueprint $table) {
            $table->dropColumn('number');
        });
    }
};
