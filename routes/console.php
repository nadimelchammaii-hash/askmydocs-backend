<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Runs when the scheduler runs: `php artisan schedule:work` locally, cron + `schedule:run` in production.
Schedule::command('documents:prune-orphans')->hourly()->withoutOverlapping();
