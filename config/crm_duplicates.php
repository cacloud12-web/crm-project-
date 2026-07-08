<?php

return [

    'strategies' => [
        'phone' => \App\Services\Leads\DuplicateDetection\PhoneDuplicateStrategy::class,
    ],

    'attribute_strategies' => [
        'email' => ['field' => 'email_id', 'column' => 'normalized_email', 'reason' => 'duplicate_email'],
        'gst' => ['field' => 'gst_no', 'column' => 'gst_no', 'reason' => 'duplicate_gst'],
        'pan' => ['field' => 'pan_no', 'column' => 'pan_no', 'reason' => 'duplicate_pan'],
        'website' => ['field' => 'website', 'column' => 'normalized_website', 'reason' => 'duplicate_website'],
        'google_place_id' => ['field' => 'google_place_id', 'column' => 'google_place_id', 'reason' => 'duplicate_place_id'],
    ],

    'messages' => [
        'phone' => 'Duplicate Number Found. This number already exists.',
        'email' => 'Duplicate lead detected. This email already exists.',
        'gst' => 'Duplicate lead detected. This GST number already exists.',
        'pan' => 'Duplicate lead detected. This PAN already exists.',
        'website' => 'Duplicate lead detected. This website already exists.',
        'google_place_id' => 'Duplicate lead detected. This Google Maps place already exists.',
        'default' => 'Duplicate lead detected. Matching lead already exists.',
    ],

    'phone' => [
        'min_digits' => 10,
        'country_code' => '91',
    ],

    'similar_prefix_length' => (int) env('CRM_DUPLICATE_SIMILAR_PREFIX', 7),

    'manager_notification_threshold' => (int) env('CRM_DUPLICATE_NOTIFY_THRESHOLD', 5),

    'productivity' => [
        'verified_lead_points' => 2,
        'followup_completed_points' => 1,
        'unique_lead_points' => 1,
        'duplicate_attempt_penalty' => 2,
        'wrong_number_penalty' => 3,
        'communication_failure_penalty' => 1,
    ],

    'invalid_number_failure_patterns' => [
        'invalid',
        'mobile number',
        'phone number',
        'not a valid',
        'must be at least 10 digits',
    ],

    'wrong_number_statuses' => ['Wrong Number', 'Number Missing'],
    'wrong_number_outcomes' => ['Wrong Number'],

];
