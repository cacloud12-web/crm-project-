<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Demo provider defaults (structural only)
    |--------------------------------------------------------------------------
    | Provider names, meeting links, and team-size tiers are stored in the
    | demo_providers database table and managed from Settings → Demo Providers.
    */
    'default_slot_duration_minutes' => 60,
    'default_buffer_minutes' => 15,
    'default_max_demos_per_day' => 6,
    'default_work_start' => '10:00:00',
    'default_work_end' => '19:00:00',
    'default_break_start' => '13:00:00',
    'default_break_end' => '14:00:00',
    'default_working_days' => [1, 2, 3, 4, 5, 6],
];
