<?php

return [

    'default_provider' => 'cloud desk',

    'env_defaults' => [
        'provider_name' => env('SMTP_PROVIDER_NAME', 'Cloud Desk'),
        'smtp_host' => env('SMTP_HOST', 'smtpout.secureserver.net'),
        'smtp_port' => (int) env('SMTP_PORT', 465),
        'smtp_username' => env('SMTP_USERNAME', 'cacloud12@gmail.com'),
        'smtp_password' => env('SMTP_PASSWORD'),
        'smtp_encryption' => env('SMTP_ENCRYPTION', 'ssl'),
        'from_email' => env('SMTP_FROM_EMAIL', 'cacloud12@gmail.com'),
        'from_name' => env('SMTP_FROM_NAME', 'CA Cloud Desk'),
        'reply_to_email' => env('SMTP_REPLY_TO_EMAIL', 'cacloud12@gmail.com'),
        'mode' => env('SMTP_MODE', 'live'),
    ],

    'log_statuses' => [
        'pending' => 'Pending',
        'queued' => 'Queued',
        'processing' => 'Processing',
        'sent' => 'Sent',
        'delivered' => 'Delivered',
        'failed' => 'Failed',
        'invalid_email' => 'Invalid Email',
        'invalid_domain' => 'Invalid Domain',
        'duplicate' => 'Duplicate',
        'skipped' => 'Skipped',
    ],

    'blocked_domains' => [
        'example.com',
        'example.org',
        'example.net',
        'test.com',
        'demo.com',
        'localhost',
        'invalid.local',
    ],

    'skip_mx_check' => (bool) env('EMAIL_SKIP_MX_CHECK', false),

    /*
    |--------------------------------------------------------------------------
    | CRM template variables (curly-brace placeholders)
    |--------------------------------------------------------------------------
    */
    'template_variables' => [
        '{CLIENT_NAME}' => 'ca_name',
        '{{CLIENT_NAME}}' => 'ca_name',
        '{CA_ORGANIZATION_NAME}' => 'firm_name',
        '{EMAIL}' => 'email_id',
        '{PHONE}' => 'mobile_no',
        '{CITY}' => 'city.city_name',
        '{STATE}' => 'state.state_name',
        '{{name}}' => 'ca_name',
        '{{firm_name}}' => 'firm_name',
        '{{city}}' => 'city.city_name',
        '{{state}}' => 'state.state_name',
        '{{mobile}}' => 'mobile_no',
        '{{email}}' => 'email_id',
        '{{SERVICE_NAME}}' => 'service_name',
        '{{INVOICE_DATE}}' => 'invoice_date',
        '{{INVOICE_AMOUNT}}' => 'invoice_amount',
        '{{DUE_DATE}}' => 'due_date',
        '{SERVICE_NAME}' => 'service_name',
        '{INVOICE_DATE}' => 'invoice_date',
        '{INVOICE_AMOUNT}' => 'invoice_amount',
        '{DUE_DATE}' => 'due_date',
    ],

];
