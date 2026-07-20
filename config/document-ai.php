<?php

$location = strtolower((string) env('GOOGLE_DOCUMENT_AI_LOCATION', 'us'));

return [
    // Prefer GOOGLE_DOCUMENT_AI_PROJECT_ID; accept GOOGLE_CLOUD_PROJECT_ID as alias.
    'project_id' => env('GOOGLE_DOCUMENT_AI_PROJECT_ID') ?: env('GOOGLE_CLOUD_PROJECT_ID'),

    /**
     * Processor location (region), normally us or eu — not language.
     */
    'location' => $location,

    'processor_id' => env('GOOGLE_DOCUMENT_AI_PROCESSOR_ID'),

    /**
     * Absolute path or path relative to the Laravel base path.
     * Also checked: GOOGLE_APPLICATION_CREDENTIALS env and
     * storage/app/google/document-ai-service-account.json
     */
    'credentials' => env('GOOGLE_DOCUMENT_AI_CREDENTIALS') ?: env('GOOGLE_APPLICATION_CREDENTIALS'),

    'timeout' => (int) env('GOOGLE_DOCUMENT_AI_TIMEOUT', 120),

    /**
     * Absolute application upload ceiling (validated on HTTP upload).
     * Prefer the higher of online/batch limits so large PDFs can be accepted for batch.
     */
    'max_file_mb' => (int) env(
        'GOOGLE_DOCUMENT_AI_MAX_FILE_MB',
        max(
            (int) env('GOOGLE_DOCUMENT_AI_ONLINE_MAX_FILE_MB', 40),
            (int) env('GOOGLE_DOCUMENT_AI_BATCH_MAX_FILE_MB', 1024),
        ),
    ),

    'online_max_pages' => (int) env('GOOGLE_DOCUMENT_AI_ONLINE_MAX_PAGES', 30),
    'batch_max_pages' => (int) env('GOOGLE_DOCUMENT_AI_BATCH_MAX_PAGES', 500),
    'online_max_file_mb' => (int) env('GOOGLE_DOCUMENT_AI_ONLINE_MAX_FILE_MB', 40),
    'batch_max_file_mb' => (int) env('GOOGLE_DOCUMENT_AI_BATCH_MAX_FILE_MB', 1024),

    'batch_poll_seconds' => max(5, (int) env('GOOGLE_DOCUMENT_AI_BATCH_POLL_SECONDS', 10)),
    'batch_timeout_minutes' => max(5, (int) env('GOOGLE_DOCUMENT_AI_BATCH_TIMEOUT_MINUTES', 60)),

    /*
    |--------------------------------------------------------------------------
    | Small-file online OCR fast path
    |--------------------------------------------------------------------------
    |
    | When enabled, eligible one-page (or few-page) documents are processed in
    | the upload request so the UI can show Completed without waiting for a worker.
    | Never used for batch / large documents.
    |
    */
    'sync_small_files' => filter_var(env('GOOGLE_DOCUMENT_AI_SYNC_SMALL_FILES', true), FILTER_VALIDATE_BOOLEAN),
    'sync_max_pages' => max(1, (int) env('GOOGLE_DOCUMENT_AI_SYNC_MAX_PAGES', 5)),
    'sync_max_file_mb' => max(1, (int) env('GOOGLE_DOCUMENT_AI_SYNC_MAX_FILE_MB', 5)),
    'sync_timeout_seconds' => max(15, (int) env('GOOGLE_DOCUMENT_AI_SYNC_TIMEOUT_SECONDS', 60)),

    /*
    |--------------------------------------------------------------------------
    | Queue routing for OCR jobs
    |--------------------------------------------------------------------------
    |
    | Jobs currently dispatch to this named queue (default Laravel "default").
    | Workers should listen to both ocr and default for compatibility:
    |   php artisan queue:work --queue=ocr,default --sleep=1 --tries=3 --timeout=300
    |
    */
    'queue' => env('GOOGLE_DOCUMENT_AI_QUEUE', 'ocr'),
    'queue_worker_list' => env('GOOGLE_DOCUMENT_AI_QUEUE_LIST', 'ocr,ocr-import,default'),
    /** Queue used by afterResponse drain (must NOT include Master CA import). */
    'drain_queue' => env('GOOGLE_DOCUMENT_AI_DRAIN_QUEUE', 'ocr'),
    /** Dedicated queue for long Master CA writes — keep out of HTTP afterResponse drain. */
    'import_queue' => env('GOOGLE_DOCUMENT_AI_IMPORT_QUEUE', 'ocr-import'),
    'auto_drain_after_dispatch' => filter_var(env('CRM_QUEUE_AUTO_DRAIN', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Memory-safe online Document AI options
    |--------------------------------------------------------------------------
    |
    | imageless_mode drops rendered page images from the ProcessResponse.
    | Without this, REST/JSON hydration base64-decodes multi-MB page images and
    | can OOM a 128MB PHP process on a tiny multi-page PDF.
    |
    */
    'imageless_mode' => filter_var(env('GOOGLE_DOCUMENT_AI_IMAGELESS_MODE', true), FILTER_VALIDATE_BOOLEAN),
    'process_field_mask' => [
        'text',
        'entities',
        'pages.paragraphs',
        'pages.tables',
        'pages.detectedLanguages',
        'pages.layout',
    ],

    'queued_stuck_minutes' => max(2, (int) env('GOOGLE_DOCUMENT_AI_QUEUED_STUCK_MINUTES', 5)),
    'processing_stuck_minutes' => max(5, (int) env('GOOGLE_DOCUMENT_AI_PROCESSING_STUCK_MINUTES', 15)),

    'gcs' => [
        // Bucket names only (no gs://). gs:// prefixes are stripped at boot.
        'input_bucket' => (static function () {
            $value = trim((string) env('GOOGLE_CLOUD_STORAGE_INPUT_BUCKET', ''));
            $value = preg_replace('#^gs://#i', '', $value) ?? $value;
            $value = trim($value, '/');

            return str_contains($value, '/') ? explode('/', $value, 2)[0] : $value;
        })(),
        'output_bucket' => (static function () {
            $value = trim((string) env('GOOGLE_CLOUD_STORAGE_OUTPUT_BUCKET', ''));
            $value = preg_replace('#^gs://#i', '', $value) ?? $value;
            $value = trim($value, '/');

            return str_contains($value, '/') ? explode('/', $value, 2)[0] : $value;
        })(),
        'input_prefix' => trim((string) env('GOOGLE_CLOUD_STORAGE_INPUT_PREFIX', 'ocr-input'), '/'),
        'output_prefix' => trim((string) env('GOOGLE_CLOUD_STORAGE_OUTPUT_PREFIX', 'ocr-output'), '/'),
        'delete_input_after_success' => filter_var(
            env('GOOGLE_OCR_DELETE_GCS_INPUT_AFTER_SUCCESS', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'delete_output_after_success' => filter_var(
            env('GOOGLE_OCR_DELETE_GCS_OUTPUT_AFTER_SUCCESS', false),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'retention_days' => max(1, (int) env('GOOGLE_OCR_GCS_RETENTION_DAYS', 7)),
    ],

    'allowed_locations' => ['us', 'eu'],

    'supported_mime_types' => [
        'application/pdf',
        'application/x-pdf',
        'image/jpeg',
        'image/png',
        'image/tiff',
    ],

    'supported_extensions' => [
        'pdf',
        'jpg',
        'jpeg',
        'png',
        'tif',
        'tiff',
    ],

    'storage_disk' => 'local',

    /*
    |--------------------------------------------------------------------------
    | Derived settings
    |--------------------------------------------------------------------------
    |
    | Endpoint: {location}-documentai.googleapis.com
    | Processor: projects/{project_id}/locations/{location}/processors/{processor_id}
    |
    */
    'api_endpoint' => sprintf('%s-documentai.googleapis.com', $location),
];
