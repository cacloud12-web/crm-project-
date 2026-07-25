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
    | When true (default), imports above import_sync_row_limit run via
    | dispatchAfterResponse (sync after the HTTP response) so Hostinger /
    | shared hosts without a dedicated queue worker still process imports.
    | Set false only when a long-running `queue:work` / Supervisor is running.
    |
    */

    'import_process_inline' => filter_var(
        env('CRM_IMPORT_PROCESS_INLINE', true),
        FILTER_VALIDATE_BOOL,
    ),

    /*
    |--------------------------------------------------------------------------
    | Rows per queued import job run
    |--------------------------------------------------------------------------
    |
    | Large CA Master imports are processed in bounded batches so Hostinger
    | cron drains (--max-time=55) can finish a chunk and dispatch the next.
    |
    */

    'import_batch_rows' => (int) env('CRM_IMPORT_BATCH_ROWS', 800),

    /*
    |--------------------------------------------------------------------------
    | Mapping-engine batch size (rows per processBatch call)
    |--------------------------------------------------------------------------
    |
    | Bulk CA Master import buffers this many importable rows before calling
    | MasterDataMappingService::processBatch once (shared index + batch row).
    |
    */

    'import_engine_batch_rows' => (int) env('CRM_IMPORT_ENGINE_BATCH_ROWS', 250),

    /*
    |--------------------------------------------------------------------------
    | Queue large imports earlier
    |--------------------------------------------------------------------------
    |
    | Files above this size always use the background job path (still may run
    | inline via dispatchAfterResponse when import_process_inline=true).
    |
    */

    'import_queue_row_threshold' => (int) env('CRM_IMPORT_QUEUE_ROW_THRESHOLD', 5000),

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
