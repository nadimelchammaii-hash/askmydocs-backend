<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    |
    | top_k: chunks sent to the model as sources.
    | min_similarity: if even the best chunk scores below this (cosine
    | similarity, 0..1), the question is answered with "not found" without
    | calling the model. Calibrated on real data: relevant chunks scored
    | 0.29-0.43 and unrelated ones 0.10-0.18 with text-embedding-3-small.
    |
    */

    'top_k' => (int) env('RAG_TOP_K', 5),

    'min_similarity' => (float) env('RAG_MIN_SIMILARITY', 0.20),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Protect the OpenAI budget and keep prompts small.
    |
    */

    'max_question_chars' => (int) env('QUESTION_MAX_CHARS', 1000),

    'questions_per_minute' => (int) env('QUESTIONS_PER_MINUTE', 10),

    'questions_per_day' => (int) env('QUESTIONS_PER_DAY', 100),

];
