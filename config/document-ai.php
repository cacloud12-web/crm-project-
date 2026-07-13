<?php

return [
    'project_id' => env('GOOGLE_DOCUMENT_AI_PROJECT_ID'),

    'location' => env('GOOGLE_DOCUMENT_AI_LOCATION', 'us'),

    'processor_id' => env('GOOGLE_DOCUMENT_AI_PROCESSOR_ID'),

  /**
   * Absolute path or path relative to the Laravel base path.
   * Default local fallback: storage/app/google/document-ai-service-account.json
   */
    'credentials' => env('GOOGLE_DOCUMENT_AI_CREDENTIALS'),

    'timeout' => (int) env('GOOGLE_DOCUMENT_AI_TIMEOUT', 120),

    'max_file_mb' => (int) env('GOOGLE_DOCUMENT_AI_MAX_FILE_MB', 10),

    'allowed_locations' => ['us', 'eu'],

    'supported_mime_types' => [
        'application/pdf',
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
];
