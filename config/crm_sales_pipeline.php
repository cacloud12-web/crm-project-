<?php

/**
 * Sales pipeline — seven stages for the Leads hub kanban.
 */
return [
    'stage_statuses' => [
        'New Lead' => ['New', 'Cold'],
        'Details Shared' => ['Details Shared', 'Pipeline', 'Warm', 'Follow Up Scheduled', 'Follow Up Reminder'],
        'Demo Scheduled' => ['Demo Scheduled'],
        'Demo Completed' => ['Demo Completed', 'Next Week', 'Next Month', 'Hold'],
        'Negotiation' => ['Hot', 'Negotiation', 'Interested', 'Thinking', 'Purchasing'],
        'Won' => ['Active', 'Purchased', 'Purchasing'],
        'Lost' => ['Lost', 'Inactive', 'Not Interested'],
    ],

    'stage_to_status' => [
        'New Lead' => 'New',
        'Details Shared' => 'Pipeline',
        'Demo Scheduled' => 'Demo Scheduled',
        'Demo Completed' => 'Demo Completed',
        'Negotiation' => 'Hot',
        'Won' => 'Active',
        'Lost' => 'Lost',
    ],

    'columns' => [
        ['key' => 'New Lead', 'label' => 'New Lead', 'icon' => 'sparkles', 'theme' => 'new-lead'],
        ['key' => 'Details Shared', 'label' => 'Details Shared', 'icon' => 'share-2', 'theme' => 'details-shared'],
        ['key' => 'Demo Scheduled', 'label' => 'Demo Scheduled', 'icon' => 'calendar-plus', 'theme' => 'demo-scheduled'],
        ['key' => 'Demo Completed', 'label' => 'Demo Completed', 'icon' => 'video', 'theme' => 'demo-completed'],
        ['key' => 'Negotiation', 'label' => 'Negotiation', 'icon' => 'handshake', 'theme' => 'negotiation'],
        ['key' => 'Won', 'label' => 'Won', 'icon' => 'circle-check', 'theme' => 'won'],
        ['key' => 'Lost', 'label' => 'Lost', 'icon' => 'x-circle', 'theme' => 'lost'],
    ],
];
