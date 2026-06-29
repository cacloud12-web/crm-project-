<?php

return [
    'outcomes' => [
        'Interested' => [
            'requires_followup' => true,
            'followup_type' => 'Call',
            'priority' => 'High',
            'advance_sequence' => false,
        ],
        'Busy' => [
            'requires_followup' => true,
            'followup_type' => 'Call',
            'priority' => 'Normal',
            'advance_sequence' => true,
        ],
        'No Answer' => [
            'requires_followup' => true,
            'followup_type' => 'Call',
            'priority' => 'Normal',
            'advance_sequence' => true,
        ],
        'Call Later' => [
            'requires_followup' => true,
            'followup_type' => 'Call',
            'priority' => 'Normal',
            'advance_sequence' => false,
            'manual_schedule' => true,
        ],
        'Demo Scheduled' => [
            'requires_followup' => true,
            'followup_type' => 'Demo Scheduled',
            'priority' => 'High',
            'advance_sequence' => false,
            'closes_sequence' => true,
        ],
        'Demo Completed' => [
            'requires_followup' => false,
            'complete_current' => true,
            'closes_sequence' => true,
        ],
        'Not Interested' => [
            'requires_followup' => false,
            'complete_current' => true,
            'closes_sequence' => true,
            'final_status' => 'Closed',
        ],
    ],

    'default_sequence_days' => [1, 3, 7, 15, 30],

    'sequence_trigger_outcomes' => ['No Answer', 'Busy'],

    'reminder_offsets' => [
        ['type' => '1_day_before', 'minutes_before' => 24 * 60],
        ['type' => '1_hour_before', 'minutes_before' => 60],
        ['type' => '15_minutes_before', 'minutes_before' => 15],
    ],

    'open_statuses' => ['Pending', 'Scheduled', 'Open', 'Overdue'],

    'completed_statuses' => ['Completed', 'Closed'],

    'priorities' => ['Low', 'Normal', 'High', 'Urgent'],
];
