<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CA Cloud Desk / LawSeva external auth APIs (tickets)
    |--------------------------------------------------------------------------
    |
    | Auth: shared secret in header X-Api-Key (env EXTERNAL_AUTH_KEY or
    | CA_CLOUD_DESK_API_TOKEN). Do not send JWT / Authorization Bearer.
    |
    | Documented paths (admin_settings):
    |   GET  /seva-api/v1/admin_settings/auth_organizations/
    |   GET  /seva-api/v1/admin_settings/auth_employee/?organization=&username=
    |   POST /seva-api/v1/admin_settings/auth_ticket/
    |
    */

    'enabled' => filter_var(env('CA_CLOUD_DESK_INTEGRATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'base_url' => env('CA_CLOUD_DESK_BASE_URL'),

    // Shared secret for X-Api-Key. Prefer EXTERNAL_AUTH_KEY; fall back to CA_CLOUD_DESK_API_TOKEN.
    'api_token' => env('EXTERNAL_AUTH_KEY', env('CA_CLOUD_DESK_API_TOKEN')),

    'api_key_header' => env('CA_CLOUD_DESK_API_KEY_HEADER', 'X-Api-Key'),

    // Official LawSeva external auth endpoints (relative to base_url unless absolute).
    'organizations_endpoint' => env(
        'CA_CLOUD_DESK_ORGANIZATIONS_ENDPOINT',
        '/seva-api/v1/admin_settings/auth_organizations/',
    ),

    'employee_endpoint' => env(
        'CA_CLOUD_DESK_EMPLOYEE_ENDPOINT',
        '/seva-api/v1/admin_settings/auth_employee/',
    ),

    'ticket_endpoint' => env(
        'CA_CLOUD_DESK_TICKET_ENDPOINT',
        '/seva-api/v1/admin_settings/auth_ticket/',
    ),

    // Backward-compatible aliases used by earlier scaffolding / isConfigured checks.
    'lookup_endpoint' => env(
        'CA_CLOUD_DESK_LOOKUP_ENDPOINT',
        env('CA_CLOUD_DESK_ORGANIZATIONS_ENDPOINT', '/seva-api/v1/admin_settings/auth_organizations/'),
    ),

    'verify_endpoint' => env(
        'CA_CLOUD_DESK_VERIFY_ENDPOINT',
        env('CA_CLOUD_DESK_EMPLOYEE_ENDPOINT', '/seva-api/v1/admin_settings/auth_employee/'),
    ),

    'timeout' => (int) env('CA_CLOUD_DESK_TIMEOUT', 20),

    // Backward-compatible alias used by earlier Phase 3 scaffolding.
    'timeout_seconds' => (int) env('CA_CLOUD_DESK_TIMEOUT', 20),

    'retry_times' => (int) env('CA_CLOUD_DESK_RETRY_TIMES', 2),

    'retry_sleep_ms' => (int) env('CA_CLOUD_DESK_RETRY_SLEEP_MS', 500),

    'inbound_integration_token' => env('CA_CLOUD_DESK_INTEGRATION_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Outbound ticket category mapping (CRM → LawSeva auth_ticket)
    |--------------------------------------------------------------------------
    |
    | CRM problem_type values (config/crm_tickets.php): issue, improvement, new_feature
    | LawSeva auth_ticket.category accepts a free-form string (e.g. "Reports",
    | "Add Document Template"). Inbound webhook heuristically maps LawSeva category
    | → problem_type; outbound uses this explicit map. Override via env if LawSeva
    | expects different labels.
    |
    */
    'problem_type_category_map' => [
        'issue' => env('CA_CLOUD_DESK_CATEGORY_ISSUE', 'Issue'),
        'improvement' => env('CA_CLOUD_DESK_CATEGORY_IMPROVEMENT', 'Improvement'),
        'new_feature' => env('CA_CLOUD_DESK_CATEGORY_NEW_FEATURE', 'New Feature'),
    ],

    'default_category' => env('CA_CLOUD_DESK_DEFAULT_CATEGORY', 'Issue'),

    /*
    |--------------------------------------------------------------------------
    | Inbound webhook (LawSeva → CRM)
    |--------------------------------------------------------------------------
    |
    | LawSeva should POST new/updated tickets to:
    |   POST {CRM_BASE_URL}/webhooks/ca-cloud-desk/tickets
    | Header:
    |   X-Integration-Token: {CA_CLOUD_DESK_INTEGRATION_TOKEN}
    |   (X-Api-Key with the same token is also accepted)
    |
    */

];
