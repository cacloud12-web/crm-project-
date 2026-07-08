<?php

return [
    'call_statuses' => [
        'Connected',
        'Not Connected',
        'Busy',
        'Wrong Number',
        'Call Back Later',
    ],

    'demo_results' => [
        'Interested',
        'Thinking',
        'Purchasing',
        'Purchased',
        'Not Interested',
        'Next Week',
        'Next Month',
        'Hold',
    ],

    'followup_offsets' => [
        'Next Week' => 7,
        'Next Month' => 30,
        'Hold' => 14,
        'Thinking' => 7,
        'Call Back Later' => 1,
        'Busy' => 1,
        'Not Connected' => 1,
    ],

    'demo_reminder_offsets' => [
        ['type' => 'scheduled_immediate', 'minutes_before' => null],
        ['type' => '15_minutes_before', 'minutes_before' => 15],
        ['type' => 'demo_day_link', 'minutes_before' => null, 'at_start_of_day' => true],
    ],

    'messages' => [
        'scheduled_immediate' => 'Your demo has been scheduled for {{demo_at}}. Firm: {{firm_name}}. Employee: {{employee_name}}. Link: {{meeting_link}}',
        '15_minutes_before' => 'Reminder: your demo will start in 15 minutes ({{demo_at}}). Firm: {{firm_name}}. Link: {{meeting_link}}',
        'demo_day_link' => 'Training/demo link for {{customer_name}} ({{firm_name}}) today at {{demo_at}}: {{meeting_link}}',
    ],
];
