<?php

/**
 * Master Data pipeline — four sales stages for the Master Pipeline kanban.
 *
 * Legacy CRM statuses are grouped into these stages for display; drag-and-drop
 * writes the canonical status for each stage to ca_masters.status.
 */
return [
    'stage_statuses' => [
        'New Lead' => [
            'New',
            'Cold',
            'Lost',
            'Inactive',
            'Not Interested',
        ],
        'Contacted' => [
            'Contacted',
            'Details Shared',
            'Pipeline',
            'Warm',
            'Follow Up Scheduled',
            'Follow Up Reminder',
            'Demo Scheduled',
        ],
        'Interested' => [
            'Interested',
            'Thinking',
            'Hot',
            'Negotiation',
            'Demo Completed',
            'Next Week',
            'Next Month',
            'Hold',
            'Purchasing',
        ],
        'Converted' => [
            'Converted',
            'Active',
            'Purchased',
        ],
    ],

    'stage_to_status' => [
        'New Lead' => 'New',
        'Contacted' => 'Contacted',
        'Interested' => 'Interested',
        'Converted' => 'Converted',
    ],

    'columns' => [
        ['key' => 'New Lead', 'label' => 'New Lead', 'icon' => '🆕', 'theme' => 'new-lead'],
        ['key' => 'Contacted', 'label' => 'Contacted', 'icon' => '📞', 'theme' => 'contacted'],
        ['key' => 'Interested', 'label' => 'Interested', 'icon' => '🤝', 'theme' => 'interested'],
        ['key' => 'Converted', 'label' => 'Converted', 'icon' => '🎉', 'theme' => 'converted'],
    ],
];
