<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Background job thresholds
    |--------------------------------------------------------------------------
    |
    | Work above these limits is dispatched to the queue worker instead of
    | blocking the HTTP request.
    |
    */

    'import_sync_row_limit' => (int) env('CRM_IMPORT_SYNC_ROW_LIMIT', 100),

    /*
    |--------------------------------------------------------------------------
    | Inline large imports
    |--------------------------------------------------------------------------
    |
    | When true, imports above import_sync_row_limit run in the HTTP request
    | instead of waiting for a queue worker. Keep this false for responsive UI.
    |
    */

    'import_process_inline' => filter_var(
        env('CRM_IMPORT_PROCESS_INLINE', false),
        FILTER_VALIDATE_BOOL,
    ),

    'campaign_log_sync_limit' => (int) env('CRM_CAMPAIGN_LOG_SYNC_LIMIT', 50),

    'report_export_sync_row_limit' => (int) env('CRM_REPORT_EXPORT_SYNC_ROW_LIMIT', 500),

    'login_max_attempts' => (int) env('CRM_LOGIN_MAX_ATTEMPTS', 5),

    'login_decay_minutes' => (int) env('CRM_LOGIN_DECAY_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Scheduled queue drain (production / demo without a long-running worker)
    |--------------------------------------------------------------------------
    |
    | When true, Laravel scheduler runs `queue:work --stop-when-empty` every
    | minute so pending jobs in the database queue are processed automatically.
    | For production, prefer a dedicated supervisor/systemd queue worker.
    |
    */

    'auto_drain' => filter_var(env('CRM_QUEUE_AUTO_DRAIN', true), FILTER_VALIDATE_BOOL),

];
