<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot Laravel and run against the PostgreSQL test database
| (askmydocs_test), migrated fresh and wrapped in a transaction per test.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** Whether the configured Poppler binaries can run (tests that need them are skipped otherwise). */
function popplerAvailable(): bool
{
    try {
        return Process::run([config('documents.pdftotext_binary'), '-v'])->successful();
    } catch (Throwable) {
        return false;
    }
}
