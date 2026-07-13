<?php

return [
    /*
    | Standard planning allowance: Sundays + company holidays + employee leave pool.
    | Actual Sunday count is calculated from the calendar; 52 is the nominal figure.
    */
    'standard_non_working_days' => (int) config('crm_targets.yearly_non_working_days', 76),

    'leave_allowance' => 12,

    'company_holiday_count' => 12,

    /*
    | Movable festivals whose exact date may differ each year.
    | Managers can override the date per year without changing the master list.
    */
    'movable_holiday_names' => [
        'Holi',
        'Good Friday',
        'Janmashtami',
        'Dussehra',
        'Diwali',
    ],
];
