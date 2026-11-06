<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Runs when the scheduler runs: `php artisan schedule:work` locally, cron + `schedule:run` in production.
Schedule::command('documents:prune-orphans')->hourly()->withoutOverlapping();

// Demo accounts ("Try the demo") whose time is up, with their documents and files.
Schedule::command('demo:prune')->hourly()->withoutOverlapping();

// Answers and documents that will not finish on their own (e.g. no worker running).
Schedule::command('work:fail-stalled')->everyFiveMinutes()->withoutOverlapping();

// Housekeeping: failed queue jobs after a week, expired password reset tokens.
Schedule::command('queue:prune-failed', ['--hours' => 168])->daily();
Schedule::command('auth:clear-resets')->daily();
