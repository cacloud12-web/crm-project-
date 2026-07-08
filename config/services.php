<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        /*
         * One Google Cloud API key can power both server-side Places and browser Maps JS.
         * Backend: GOOGLE_PLACES_API_KEY or GOOGLE_MAPS_API_KEY (server IP / none restrictions).
         * Frontend Maps JS only: VITE_GOOGLE_MAPS_API_KEY (HTTP referrer restrictions).
         */
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY', env('GOOGLE_PLACES_API_KEY')),
        'places_api_key' => env('GOOGLE_PLACES_API_KEY', env('GOOGLE_MAPS_API_KEY')),
        'maps_js_api_key' => env('VITE_GOOGLE_MAPS_API_KEY', env('GOOGLE_MAPS_API_KEY', env('GOOGLE_PLACES_API_KEY'))),
    ],

];
