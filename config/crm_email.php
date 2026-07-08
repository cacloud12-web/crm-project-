<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IMAP inbox sync
    |--------------------------------------------------------------------------
    |
    | HTTP "Sync Latest Emails" uses quick mode (single IMAP query, newest first).
    | Scheduler `email:sync` uses full mode (date + UID window) every 5 minutes.
    |
    */

    'imap_sync_quick_limit' => (int) env('CRM_IMAP_SYNC_QUICK_LIMIT', 15),

    'imap_sync_scheduled_limit' => (int) env('CRM_IMAP_SYNC_SCHEDULED_LIMIT', 50),

    'imap_sync_http_timeout_seconds' => (int) env('CRM_IMAP_SYNC_HTTP_TIMEOUT', 35),

    'imap_client_timeout_seconds' => (int) env('CRM_IMAP_CLIENT_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Inbox auto-sync poll (browser)
    |--------------------------------------------------------------------------
    |
    | While Communication → Email is open, poll for new replies every N minutes
    | without requiring php artisan schedule:work in local development.
    |
    */

    'inbox_auto_sync_minutes' => (int) env('CRM_INBOX_AUTO_SYNC_MINUTES', 5),

];
