<?php

return [

    'token_expiry_hours' => (int) env('LOGIN_EMAIL_CHANGE_EXPIRY_HOURS', 24),

    'mail_from_name' => env('LOGIN_EMAIL_CHANGE_FROM_NAME', env('SMTP_FROM_NAME', 'CA Cloud Desk CRM')),

    'blocked_domains' => [
        'example.com',
        'example.org',
        'example.net',
        'test.com',
        'localhost',
        'local',
        'invalid.local',
        'mailinator.com',
        'yopmail.com',
    ],

    'blocked_domain_suffixes' => [
        '.local',
        '.admin',
    ],

];
