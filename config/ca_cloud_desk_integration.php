<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CA Cloud Desk integration (placeholders — no invented endpoints)
    |--------------------------------------------------------------------------
    |
    | Real URL paths and response fields must come from the CA Cloud Desk
    | developer. Empty values mean the integration is not configured.
    |
    | This layer is provider-ready: when official docs arrive, fill the env
    | values and implement response mapping in CaCloudDeskHttpClient.
    |
    */

    'enabled' => filter_var(env('CA_CLOUD_DESK_INTEGRATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'base_url' => env('CA_CLOUD_DESK_BASE_URL'),

    'api_token' => env('CA_CLOUD_DESK_API_TOKEN'),

    'lookup_endpoint' => env('CA_CLOUD_DESK_LOOKUP_ENDPOINT'),

    'verify_endpoint' => env('CA_CLOUD_DESK_VERIFY_ENDPOINT'),

    'timeout' => (int) env('CA_CLOUD_DESK_TIMEOUT', 20),

    // Backward-compatible alias used by earlier Phase 3 scaffolding.
    'timeout_seconds' => (int) env('CA_CLOUD_DESK_TIMEOUT', 20),

    'retry_times' => (int) env('CA_CLOUD_DESK_RETRY_TIMES', 2),

    'retry_sleep_ms' => (int) env('CA_CLOUD_DESK_RETRY_SLEEP_MS', 500),

    'inbound_integration_token' => env('CA_CLOUD_DESK_INTEGRATION_TOKEN'),

];
