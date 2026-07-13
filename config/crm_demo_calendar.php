<?php

return [
    /*
    | Company demo scheduling rules (Mon–Sat working week).
    | Sunday is closed. Demos may run 10:00 AM – 7:00 PM only.
    */
    'working_days' => [1, 2, 3, 4, 5, 6],
    'closed_weekdays' => [0],
    'start_time' => '10:00',
    'end_time' => '19:00',
    'slot_minutes' => 30,

    'messages' => [
        'sunday' => 'Demos cannot be scheduled on Sundays.',
        'start_time' => 'Demo start time must be 10:00 AM or later.',
        'end_time' => 'Demo end time must be 7:00 PM or earlier.',
        'end_after_start' => 'Demo end time must be after the start time.',
    ],
];
