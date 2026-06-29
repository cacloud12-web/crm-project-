<?php

return [
    'default_per_page' => 25,
    'max_per_page' => 100,
    'max_all' => 5000,

    'ca_masters' => [
        'table' => 'ca_masters',
        'primary_key' => 'ca_id',
        'employee_scope' => 'assigned_active_leads',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['ca_id', 'firm_name', 'ca_name', 'status', 'rating', 'team_size', 'created_at', 'updated_at'],
        'search_columns' => ['firm_name', 'ca_name', 'mobile_no', 'alternate_mobile_no', 'email_id', 'gst_no'],
        'search_relations' => [
            'city' => ['city_name'],
            'state' => ['state_name'],
            'sourceLead' => ['source_name'],
        ],
        'filters' => [
            'status' => 'exact',
            'state_id' => 'exact_int',
            'city_id' => 'exact_int',
            'source_id' => 'exact_int',
            'city' => 'city_name',
            'state' => 'state_name',
            'existing_software' => 'exact',
            'is_newly_established' => 'boolean',
            'team_size_min' => 'team_size_min',
            'team_size_max' => 'team_size_max',
            'rating_min' => 'rating_min',
            'rating_max' => 'rating_max',
            'segment' => 'segment',
        ],
        'date_column' => 'created_at',
    ],

    'employees' => [
        'table' => 'employees',
        'primary_key' => 'employee_id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['employee_id', 'name', 'email_id', 'role', 'status', 'date_of_joining', 'created_at'],
        'search_columns' => ['name', 'email_id', 'mobile_no', 'role'],
        'search_relations' => [
            'city' => ['city_name'],
        ],
        'filters' => [
            'status' => 'exact',
            'role' => 'exact',
            'city_id' => 'exact_int',
            'city' => 'city_name',
        ],
        'date_column' => 'created_at',
    ],

    'follow_ups' => [
        'table' => 'follow_ups',
        'primary_key' => 'followup_id',
        'employee_scope' => 'employee_id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['followup_id', 'followup_type', 'status', 'scheduled_date', 'next_followup_date', 'created_at'],
        'search_columns' => ['followup_type', 'remarks', 'status'],
        'search_relations' => [
            'caMaster' => ['firm_name', 'ca_name'],
            'employee' => ['name'],
        ],
        'filters' => [
            'status' => 'exact',
            'followup_type' => 'exact',
            'employee_id' => 'exact_int',
            'ca_id' => 'exact_int',
            'followup_due' => 'followup_due',
        ],
        'date_column' => 'scheduled_date',
    ],

    'lead_assignments' => [
        'table' => 'lead_assignment_engines',
        'primary_key' => 'assignment_id',
        'employee_scope' => 'employee_id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['assignment_id', 'assignment_type', 'status', 'created_at'],
        'search_columns' => ['assignment_type', 'rotation_logic_used', 'status'],
        'search_relations' => [
            'caMaster' => ['firm_name', 'ca_name'],
            'employee' => ['name'],
        ],
        'filters' => [
            'status' => 'exact',
            'assignment_type' => 'exact',
            'employee_id' => 'exact_int',
            'ca_id' => 'exact_int',
        ],
        'date_column' => 'created_at',
    ],

    'assignment_histories' => [
        'table' => 'assignment_histories',
        'primary_key' => 'id',
        'default_sort' => 'assigned_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['id', 'assignment_type', 'reason', 'assigned_at'],
        'search_columns' => ['assignment_type', 'reason', 'assigned_by'],
        'search_relations' => [
            'caMaster' => ['firm_name', 'ca_name'],
            'previousEmployee' => ['name'],
            'newEmployee' => ['name'],
            'assignedByEmployee' => ['name'],
        ],
        'filters' => [
            'assignment_type' => 'exact',
            'ca_id' => 'exact_int',
            'new_employee_id' => 'exact_int',
        ],
        'date_column' => 'assigned_at',
    ],

    'activity_logs' => [
        'table' => 'activity_logs',
        'primary_key' => 'id',
        'employee_scope' => 'activity_performed_by',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['id', 'module_name', 'action', 'performed_by', 'created_at'],
        'search_columns' => ['module_name', 'action', 'record_id', 'description', 'performed_by'],
        'filters' => [
            'module_name' => 'exact',
            'action' => 'exact',
            'user' => 'performed_by_ilike',
            'date' => 'date_exact',
        ],
        'date_column' => 'created_at',
    ],

    'states' => [
        'table' => 'states',
        'primary_key' => 'state_id',
        'cacheable' => false,
        'default_sort' => 'state_name',
        'default_sort_dir' => 'asc',
        'sortable' => ['state_id', 'state_name', 'created_at'],
        'search_columns' => ['state_name'],
        'filters' => [],
        'date_column' => 'created_at',
    ],

    'cities' => [
        'table' => 'cities',
        'primary_key' => 'city_id',
        'cacheable' => false,
        'default_sort' => 'city_name',
        'default_sort_dir' => 'asc',
        'sortable' => ['city_id', 'city_name', 'state_id', 'created_at'],
        'search_columns' => ['city_name'],
        'search_relations' => [
            'state' => ['state_name'],
        ],
        'filters' => [
            'state_id' => 'exact_int',
        ],
        'date_column' => 'created_at',
    ],

    'source_leads' => [
        'table' => 'source_leads',
        'primary_key' => 'source_id',
        'cacheable' => true,
        'default_sort' => 'source_name',
        'default_sort_dir' => 'asc',
        'sortable' => ['source_id', 'source_name', 'created_at'],
        'search_columns' => ['source_name'],
        'filters' => [],
        'date_column' => 'created_at',
    ],

    'team_sizes' => [
        'table' => 'team_size_masters',
        'primary_key' => 'team_size_id',
        'cacheable' => true,
        'default_sort' => 'team_size_label',
        'default_sort_dir' => 'asc',
        'sortable' => ['team_size_id', 'team_size_label', 'team_size_min', 'team_size_max'],
        'search_columns' => ['team_size_label'],
        'filters' => [],
        'date_column' => 'created_at',
    ],

    'role_masters' => [
        'table' => 'role_masters',
        'primary_key' => 'role_id',
        'cacheable' => true,
        'default_sort' => 'role_name',
        'default_sort_dir' => 'asc',
        'sortable' => ['role_id', 'role_name', 'created_at'],
        'search_columns' => ['role_name'],
        'filters' => [],
        'date_column' => 'created_at',
    ],

    'consent_trackings' => [
        'table' => 'consent_trackings',
        'primary_key' => 'id',
        'default_sort' => 'updated_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['id', 'consent_type', 'consent_status', 'consent_date', 'updated_at'],
        'search_columns' => ['consent_type', 'consent_status'],
        'search_relations' => [
            'caMaster' => ['firm_name', 'mobile_no', 'email_id'],
        ],
        'filters' => [
            'consent_type' => 'exact',
            'consent_status' => 'exact',
        ],
        'date_column' => 'consent_date',
    ],

    'dnd_management' => [
        'table' => 'dnd_management',
        'primary_key' => 'id',
        'default_sort' => 'added_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['id', 'dnd_type', 'added_at', 'updated_at'],
        'search_columns' => ['dnd_type', 'reason', 'mobile_no', 'email_id'],
        'search_relations' => [
            'caMaster' => ['firm_name', 'mobile_no', 'email_id'],
        ],
        'filters' => [
            'dnd_type' => 'exact',
        ],
        'date_column' => 'added_at',
    ],

    'whatsapp_campaigns' => [
        'table' => 'whatsapp_campaigns',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['id', 'campaign_name', 'status', 'scheduled_at', 'created_at'],
        'search_columns' => ['campaign_name', 'status', 'audience_mode'],
        'filters' => [
            'status' => 'exact',
            'audience_mode' => 'exact',
        ],
        'date_column' => 'created_at',
    ],

    'email_campaigns' => [
        'table' => 'email_campaigns',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['id', 'campaign_name', 'status', 'scheduled_at', 'created_at'],
        'search_columns' => ['campaign_name', 'status', 'audience_mode'],
        'filters' => [
            'status' => 'exact',
        ],
        'date_column' => 'created_at',
    ],

    'sms_campaigns' => [
        'table' => 'sms_campaigns',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['id', 'campaign_name', 'status', 'scheduled_at', 'created_at'],
        'search_columns' => ['campaign_name', 'status', 'audience_mode'],
        'filters' => [
            'status' => 'exact',
        ],
        'date_column' => 'created_at',
    ],

    'wa_message_logs' => [
        'table' => 'wa_message_logs',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['id', 'message_status', 'created_at'],
        'search_columns' => ['message_status', 'mobile_no', 'message'],
        'search_relations' => [
            'caMaster' => ['firm_name'],
            'campaign' => ['campaign_name'],
        ],
        'filters' => [
            'campaign_id' => 'exact_int',
            'message_status' => 'exact',
        ],
        'date_column' => 'created_at',
    ],

    'email_logs' => [
        'table' => 'email_logs',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['id', 'email_status', 'created_at'],
        'search_columns' => ['email_status', 'recipient_email', 'subject'],
        'search_relations' => [
            'caMaster' => ['firm_name'],
            'campaign' => ['campaign_name'],
        ],
        'filters' => [
            'campaign_id' => 'exact_int',
            'email_status' => 'exact',
        ],
        'date_column' => 'created_at',
    ],

    'sms_logs' => [
        'table' => 'sms_logs',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sortable' => ['id', 'sms_status', 'created_at'],
        'search_columns' => ['sms_status', 'mobile_no', 'message'],
        'search_relations' => [
            'caMaster' => ['firm_name'],
            'campaign' => ['campaign_name'],
        ],
        'filters' => [
            'campaign_id' => 'exact_int',
            'sms_status' => 'exact',
        ],
        'date_column' => 'created_at',
    ],

    'bulk_operations' => [
        'table' => 'bulk_actions',
        'primary_key' => 'bulk_action_id',
        'default_sort' => 'bulk_action_id',
        'default_sort_dir' => 'desc',
        'sortable' => ['bulk_action_id', 'action_type', 'status', 'created_at'],
        'search_columns' => ['action_type', 'file_name', 'status', 'imported_by'],
        'filters' => [
            'action_type' => 'exact',
            'status' => 'exact',
        ],
        'date_column' => 'created_at',
    ],
];
