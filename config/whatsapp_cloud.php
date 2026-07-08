<?php

return [

    'default_provider' => 'Meta WhatsApp Cloud API',

    'default_api_version' => env('WHATSAPP_API_VERSION', env('WHATSAPP_CLOUD_API_VERSION', 'v23.0')),

    'env_defaults' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'test_mobile_number' => env('WHATSAPP_TEST_MOBILE_NUMBER'),
    ],

    'graph_base_url' => env('WHATSAPP_GRAPH_BASE_URL', 'https://graph.facebook.com'),

    /*
    |--------------------------------------------------------------------------
    | Meta Graph API endpoint pattern
    |--------------------------------------------------------------------------
    */
    'messages_endpoint_pattern' => '{graph_base_url}/{api_version}/{phone_number_id}/messages',

    'message_templates_endpoint_pattern' => '{graph_base_url}/{api_version}/{business_account_id}/message_templates',

    /*
    |--------------------------------------------------------------------------
    | CRM variable → lead resolution keys used by WhatsAppCloudMappingService
    |--------------------------------------------------------------------------
    */
    'template_variables' => [
        '{{name}}' => 'ca_name',
        '{{firm_name}}' => 'firm_name',
        '{{mobile}}' => 'mobile_no',
        '{{city}}' => 'city.city_name',
        '{{state}}' => 'state.state_name',
        '{{demo_date}}' => 'demo.scheduled_date',
        '{{demo_time}}' => 'demo.scheduled_time',
        '{{employee_name}}' => 'assignment.employee.name',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default header documents for Meta templates that require a document header
    |--------------------------------------------------------------------------
    */
    'default_header_documents' => [
        'task_customermp2et391nk' => [
            'link' => env('WHATSAPP_TASK_DOCUMENT_URL', 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf'),
            'filename' => env('WHATSAPP_TASK_DOCUMENT_FILENAME', 'task-notification.pdf'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback values when a Meta template body parameter would otherwise be empty
    | Meta rejects blank text parameters (error #131008).
    |--------------------------------------------------------------------------
    */
    'meta_parameter_fallbacks' => [
        'ca_name' => 'Customer',
        'assigned_staff' => 'Not assigned',
        'employee_name' => 'CRM Team',
        'task_name' => 'Follow-up Task',
        'task_date' => null,
        'expected_completion' => null,
        'default' => 'N/A',
    ],

    'log_statuses' => [
        'pending' => 'Pending',
        'payload_generated' => 'Payload Generated',
        'queued' => 'Queued',
        'skipped' => 'Skipped',
        'failed' => 'Failed',
        'sent' => 'Sent',
        'delivered' => 'Delivered',
        'read' => 'Read',
        'api_error' => 'API Error',
    ],

];
