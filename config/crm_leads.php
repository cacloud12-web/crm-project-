<?php

return [

    'allowed_tags' => [
        'Hot',
        'Warm',
        'Cold',
        'Interested',
        'Demo Scheduled',
        'Not Interested',
        'DND',
        'Number Missing',
    ],

    'priorities' => [
        'High',
        'Medium',
        'Low',
    ],

    'research_statuses' => [
        'Pending Research',
        'Mobile Found',
        'Email Found',
        'Website Found',
        'Research Complete',
        'Unable to Contact',
    ],

    'employee_sensitive_statuses' => [
        'Lost',
        'Inactive',
        'Active',
    ],

    'employee_sensitive_actions' => [
        'Not Interested',
        'Mark Inactive',
    ],

    'lock_ttl_minutes' => (int) env('CRM_LEAD_LOCK_TTL_MINUTES', 10),

];
