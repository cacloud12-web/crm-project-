<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CRM action rate limits (requests per decay window per user/IP)
    |--------------------------------------------------------------------------
    */

    'bulk_import' => [
        'max_attempts' => (int) env('CRM_RATE_BULK_IMPORT_MAX', 10),
        'decay_minutes' => (int) env('CRM_RATE_BULK_IMPORT_DECAY', 1),
    ],

    'campaign' => [
        'max_attempts' => (int) env('CRM_RATE_CAMPAIGN_MAX', 20),
        'decay_minutes' => (int) env('CRM_RATE_CAMPAIGN_DECAY', 1),
    ],

    'follow_up' => [
        'max_attempts' => (int) env('CRM_RATE_FOLLOW_UP_MAX', 30),
        'decay_minutes' => (int) env('CRM_RATE_FOLLOW_UP_DECAY', 1),
    ],

    'lead_action' => [
        'max_attempts' => (int) env('CRM_RATE_LEAD_ACTION_MAX', 60),
        'decay_minutes' => (int) env('CRM_RATE_LEAD_ACTION_DECAY', 1),
    ],

];
