<?php

return [
    'demo_activity_enabled' => (bool) env('CRM_FOLLOWUP_ACTIVITY_DEMO', false),

    'types' => [
        'Call Status',
        'Demo Scheduled',
        'Demo Completed',
        'Demo History',
        'Details Shared',
        'Negotiation',
        'Not Interested',
        'Follow Up Reminder',
        'Follow Up Scheduled',
        'Call',
    ],

    'statuses' => [
        'Pending',
        'Scheduled',
        'Completed',
        'Closed',
        'Done',
        'Overdue',
        'Cancelled',
        'Rescheduled',
    ],
];
