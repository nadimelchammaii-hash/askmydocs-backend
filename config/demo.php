<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Accounts
    |--------------------------------------------------------------------------
    |
    | "Try the demo" creates a temporary account holding copies of the sample
    | documents. The samples are prepared once (DemoSeeder) under a hidden
    | template account; starting a demo copies their pages, chunks and
    | embeddings, so it costs no OpenAI call. demo:prune deletes expired demos.
    |
    */

    'enabled' => (bool) env('DEMO_ENABLED', true),

    // Hidden account that owns the prepared samples. ".invalid" can never receive
    // email, and the account has a random password, so nobody can sign in to it.
    'template_email' => env('DEMO_TEMPLATE_EMAIL', 'demo-template@askmydocs.invalid'),

    // Demo accounts get addresses at this domain (reserved, never deliverable).
    'email_domain' => 'demo.askmydocs.invalid',

    // Folder the seeder imports the sample PDFs from (kept out of Git: it is on the server only).
    'source_path' => env('DEMO_SOURCE_PATH', storage_path('app/demo-source')),

    'lifetime_hours' => (int) env('DEMO_LIFETIME_HOURS', 24),

    // PDFs a demo visitor may upload in addition to the samples.
    'max_uploads' => (int) env('DEMO_MAX_UPLOADS', 1),

    // Replaces the normal daily question limit (answers.questions_per_day) for demo accounts.
    'questions_per_day' => (int) env('DEMO_QUESTIONS_PER_DAY', 20),

    // New demos per visitor IP address per hour, and for the whole site per day.
    'starts_per_hour' => (int) env('DEMO_STARTS_PER_HOUR', 3),
    'starts_per_day' => (int) env('DEMO_STARTS_PER_DAY', 200),

];
