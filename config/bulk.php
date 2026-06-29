<?php

return [

    /*
  |--------------------------------------------------------------------------
  | Bulk export
  |--------------------------------------------------------------------------
  */

    'export_enabled' => env('BULK_EXPORT_ENABLED', true),

    'export_sync_row_limit' => (int) env('BULK_EXPORT_SYNC_ROW_LIMIT', 200),

    'export_chunk_size' => (int) env('BULK_EXPORT_CHUNK_SIZE', 500),

    'import_sync_row_limit' => (int) env('CRM_IMPORT_SYNC_ROW_LIMIT', 100),

];
