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

    /*
    | Sales → Master auto-match (app/Services/SalesMapping).
    | Auto-match only when exactly one unique ca_masters candidate exists
    | and confidence is >= auto_match_min_confidence.
    */
    'matching' => [
        'auto_match_min_confidence' => 0.90,
        'tier_confidence' => [
            'firm_ca_city' => 0.98,
            'firm_mobile' => 0.95,
            'ca_mobile' => 0.93,
            'normalized_firm_ca_city' => 0.92,
            'email' => 0.90,
        ],
        // Candidate search caps (safety for large Master tables).
        'candidate_limit' => 25,
    ],

    'import' => [
        'chunk_size' => 500,
    ],
];
