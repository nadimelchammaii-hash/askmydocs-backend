<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo accounts ("Try the demo" on the login page): a temporary user with copies of
 * the sample documents, deleted by demo:prune when demo_expires_at has passed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null for real accounts. Indexed for demo:prune's search for expired demos.
            $table->timestamp('demo_expires_at')->nullable()->index();
        });

        Schema::table('documents', function (Blueprint $table) {
            // A demo sample: cannot be renamed or deleted by its (demo) owner.
            $table->boolean('is_sample')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('is_sample');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['demo_expires_at']);
            $table->dropColumn('demo_expires_at');
        });
    }
};
