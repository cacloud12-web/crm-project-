<?php

return [
    'default_days' => 30,

    'won_statuses' => ['Active'],
    'lost_statuses' => ['Lost', 'Inactive'],
    'pipeline_statuses' => [
        'Pipeline',
        'Warm',
        'Demo Scheduled',
        'Demo Completed',
        'Negotiation',
        'Details Shared',
    ],
    'open_followup_statuses' => ['Pending', 'Scheduled', 'Open'],
    'completed_followup_statuses' => ['Completed', 'Done', 'Closed'],

    'reports' => [
        'lead_conversion' => [
            'label' => 'Lead Conversion',
            'description' => 'Funnel, conversion rates, and daily lead intake',
            'card' => 'Daily Lead Report',
        ],
        'employee_performance' => [
            'label' => 'Employee Performance',
            'description' => 'Assignments, targets, follow-ups, and demos by executive',
            'card' => 'Employee Performance',
        ],
        'followup_performance' => [
            'label' => 'Follow-up Performance',
            'description' => 'Scheduled, completed, and overdue follow-ups',
            'card' => 'Weekly Demo Report',
        ],
        'assignment_statistics' => [
            'label' => 'Assignment Statistics',
            'description' => 'Assignment volume, types, and reassignment trends',
            'card' => null,
        ],
        'campaign_analytics' => [
            'label' => 'Campaign Analytics',
            'description' => 'WhatsApp, email, and SMS campaign delivery metrics',
            'card' => null,
        ],
        'monthly_trends' => [
            'label' => 'Monthly Trends',
            'description' => 'Month-over-month leads, conversions, and activity',
            'card' => 'Monthly Revenue',
        ],
        'city_analysis' => [
            'label' => 'City-wise Analysis',
            'description' => 'Lead distribution and conversion by city',
            'card' => 'City-wise Analysis',
        ],
        'lost_lead_analysis' => [
            'label' => 'Lost Lead Analysis',
            'description' => 'Lost and inactive leads with assignment context',
            'card' => 'Lost Lead Analysis',
        ],
    ],

    'analytics_charts' => [
        'daily_calls' => 'Daily Calls',
        'demo_ratio' => 'Demo Ratio',
        'conversion' => 'Conversion %',
        'city_performance' => 'City Performance',
        'lead_source' => 'Lead Source',
        'target_achievement' => 'Target Achievement',
    ],
];
