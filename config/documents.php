<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Filesystem disk (config/filesystems.php) that holds uploaded PDFs.
    |
    */

    'disk' => env('DOCUMENTS_DISK', 'documents'),

    /*
    |--------------------------------------------------------------------------
    | Upload Limits
    |--------------------------------------------------------------------------
    |
    | PHP's upload_max_filesize / post_max_size must be larger than max_size_kb,
    | otherwise PHP rejects the upload before this validation can run.
    |
    */

    'max_size_kb' => (int) env('DOCUMENTS_MAX_SIZE_KB', 20 * 1024),

    'max_pages' => (int) env('DOCUMENTS_MAX_PAGES', 300),

    /*
    |--------------------------------------------------------------------------
    | Poppler Binaries
    |--------------------------------------------------------------------------
    |
    | pdftotext extracts text per page; pdfinfo reads page count and encryption.
    | Defaults rely on PATH (e.g. Docker with poppler-utils). On Windows, set
    | absolute paths in .env instead of changing the system PATH.
    |
    */

    'pdftotext_binary' => env('PDFTOTEXT_BINARY', 'pdftotext'),

    'pdfinfo_binary' => env('PDFINFO_BINARY', 'pdfinfo'),

    /*
    | Seconds before a Poppler process is killed. Must stay below the processing
    | job's timeout, which must stay below the queue's retry_after.
    */

    'process_timeout' => (int) env('PDF_PROCESS_TIMEOUT', 90),

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | Page text is split into chunks for embedding, measured in characters
    | (about 4 characters per token for English). A chunk never spans pages.
    | Changing these only affects documents processed afterwards.
    |
    */

    'chunking' => [
        'target_chars' => (int) env('CHUNK_TARGET_CHARS', 1500),
        'max_chars' => (int) env('CHUNK_MAX_CHARS', 2000),
        'overlap_chars' => (int) env('CHUNK_OVERLAP_CHARS', 200),
    ],

];
