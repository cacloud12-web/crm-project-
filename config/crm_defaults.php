<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Generic template / preview fallbacks (no real business data)
    |--------------------------------------------------------------------------
    */
    'template_preview' => [
        'ca_name' => 'Sample CA',
        'firm_name' => 'Sample Firm Pvt Ltd',
        'city' => 'Sample City',
        'state' => 'Sample State',
        'address' => '123 Sample Street',
        'pincode' => '000000',
        'mobile' => '+91 90000 00000',
        'email' => 'client@example.com',
        'employee_name' => 'Account Manager',
        'employee_email' => 'manager@example.com',
        'employee_phone' => '+91 90000 00001',
        'meeting_link' => 'https://meet.example.com/demo',
        'support_email' => env('CRM_SUPPORT_EMAIL', 'support@example.com'),
        'support_phone' => env('CRM_SUPPORT_PHONE', '+91 1800 000 000'),
        'website' => env('CRM_WEBSITE_URL', 'https://example.com'),
        'company_address' => env('CRM_COMPANY_ADDRESS', 'Sample City, Sample State'),
    ],
];
