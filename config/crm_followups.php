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
        'Do Not Disturb',
        'Follow Up Reminder',
        'Follow Up Scheduled',
        'Call',
    ],

    /** Follow-up types that only need remarks (no schedule / priority). */
    'remarks_only_types' => [
        'Not Interested',
        'Do Not Disturb',
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
