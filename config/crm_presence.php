<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Employee online window (minutes)
    |--------------------------------------------------------------------------
    |
    | An employee is considered online when users.last_seen_at is within this
    | many minutes of "now". Retained for login/logout last_seen tracking only;
    | Assignment no longer displays Present/Absent from heartbeat presence.
    |
    */
    'online_window_minutes' => max(1, (int) env('CRM_EMPLOYEE_ONLINE_WINDOW_MINUTES', 5)),

    /*
    |--------------------------------------------------------------------------
    | Client heartbeat interval (seconds)
    |--------------------------------------------------------------------------
    */
    'heartbeat_interval_seconds' => max(15, (int) env('CRM_EMPLOYEE_HEARTBEAT_INTERVAL_SECONDS', 60)),

    /*
    |--------------------------------------------------------------------------
    | Server-side presence touch throttle (seconds)
    |--------------------------------------------------------------------------
    |
    | Authenticated CRM requests may refresh last_seen_at at most this often
    | (in addition to the dedicated heartbeat endpoint).
    |
    */
    'request_touch_throttle_seconds' => max(15, (int) env('CRM_EMPLOYEE_PRESENCE_TOUCH_THROTTLE_SECONDS', 60)),

];
