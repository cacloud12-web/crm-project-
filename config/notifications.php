<?php

return [
    'poll_interval_seconds' => (int) env('CRM_NOTIFICATION_POLL_SECONDS', 30),

    'broadcast_channel_prefix' => 'crm.user.',

    'types' => [
        'lead_assigned' => [
            'label' => 'Lead Assigned',
            'severity' => 'brand',
        ],
        'followup_due' => [
            'label' => 'Follow-up Due',
            'severity' => 'amber',
        ],
        'campaign_completed' => [
            'label' => 'Campaign Completed',
            'severity' => 'emerald',
        ],
        'import_completed' => [
            'label' => 'Import Completed',
            'severity' => 'emerald',
        ],
        'export_completed' => [
            'label' => 'Export Completed',
            'severity' => 'emerald',
        ],
        'new_employee' => [
            'label' => 'New Employee',
            'severity' => 'brand',
        ],
        'activity_alert' => [
            'label' => 'Activity Alert',
            'severity' => 'amber',
        ],
        'demo_confirmed' => [
            'label' => 'Demo Confirmed',
            'severity' => 'emerald',
        ],
        'demo_rejected' => [
            'label' => 'Demo Rejected',
            'severity' => 'rose',
        ],
        'demo_rejected_after_reschedule' => [
            'label' => 'Demo Rejected After Reschedule',
            'severity' => 'rose',
        ],
        'demo_rescheduled' => [
            'label' => 'Demo Rescheduled',
            'severity' => 'amber',
        ],
        'followup_assigned' => [
            'label' => 'Follow-up Assigned',
            'severity' => 'brand',
        ],
        'followup_reminder' => [
            'label' => 'Follow-up Reminder',
            'severity' => 'amber',
        ],
        'followup_rescheduled' => [
            'label' => 'Follow-up Rescheduled',
            'severity' => 'amber',
        ],
        'followup_overdue' => [
            'label' => 'Follow-up Overdue',
            'severity' => 'rose',
        ],
        'task_overdue' => [
            'label' => 'Task Overdue',
            'severity' => 'rose',
        ],
        'demo_today' => [
            'label' => 'Demo Today',
            'severity' => 'brand',
        ],
        'missed_followup' => [
            'label' => 'Missed Follow-up',
            'severity' => 'rose',
        ],
    ],

    'activity_alert_actions' => [
        'Delete Lead',
        'Delete Employee',
        'Bulk Status Update',
        'Bulk Assignment',
        'DND Add',
        'Campaign Skip',
    ],

    'management_roles' => ['super_admin', 'admin', 'manager'],
];
