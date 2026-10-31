<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    /*
    | OpenAI: embeddings now, answers in Phase 4. The key is secret: .env only.
    | embedding_dimensions must match the document_chunks.embedding column.
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_dimensions' => (int) env('OPENAI_EMBEDDING_DIMENSIONS', 1536),
        'answer_model' => env('OPENAI_ANSWER_MODEL', 'gpt-6-luna'),
        // none | low | medium | high ... (empty: the model's default)
        'answer_reasoning_effort' => env('OPENAI_ANSWER_REASONING_EFFORT', 'low'),
        // Includes reasoning tokens; caps the cost of one answer.
        'answer_max_output_tokens' => (int) env('OPENAI_ANSWER_MAX_OUTPUT_TOKENS', 1000),
        'answer_timeout' => (int) env('OPENAI_ANSWER_TIMEOUT', 60),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
