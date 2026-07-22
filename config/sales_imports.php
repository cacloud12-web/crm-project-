<?php

return [
    /*
    | Explicit filename → employee overrides (basename only, case-insensitive keys).
    | Prefer this when filenames do not follow "CA CloudDesk Leads - NAME.csv".
    */
    'employee_map' => [
        // 'RAHUL SALES LIST.csv' => 'RAHUL',
    ],

    'directory' => 'sales-imports',

    'source_type' => 'employee_sales_list',
];
