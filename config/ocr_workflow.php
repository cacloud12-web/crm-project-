<?php

/**
 * OCR review workflow — manager-mandated 3-field scope.
 *
 * Only firm_name, ca_name, and city participate in validation, confidence,
 * matching, review, approval, and Master writes. All other OCR tokens are
 * ignored for final decisions (kept for audit only).
 */
return [

    /*
    | firm_ca_city = only Firm Name + CA Name + City (production OCR workflow).
    | full         = legacy multi-field extraction (tests / debugging only).
    */
    'mode' => env('OCR_WORKFLOW_MODE', env('OCR_WORKFLOW', 'firm_ca_city')),

    'fields' => ['firm_name', 'ca_name', 'city'],

    'matching_profile' => 'firm_ca_city',

    'require_all_three' => true,

    'exact_match_only' => true,

    /*
    | Per-field minimum confidence (parser/OCR blend). Ignored fields never
    | enter this calculation. Defaults allow typical Document AI ~91% scores.
    */
    'min_field_confidence' => (float) env('OCR_THREE_FIELD_MIN_CONFIDENCE', 0.55),
    'min_firm_name_confidence' => (float) env('OCR_FIRM_NAME_MIN_CONFIDENCE', env('OCR_THREE_FIELD_MIN_CONFIDENCE', 0.55)),
    'min_ca_name_confidence' => (float) env('OCR_CA_NAME_MIN_CONFIDENCE', env('OCR_THREE_FIELD_MIN_CONFIDENCE', 0.55)),
    'min_city_confidence' => (float) env('OCR_CITY_MIN_CONFIDENCE', env('OCR_THREE_FIELD_MIN_CONFIDENCE', 0.55)),

    /*
    | Only these collision / layout codes may block Needs Review in this workflow.
    */
    'blocking_codes' => [
        'MISSING_FIRM_NAME',
        'MISSING_CA_NAME',
        'MISSING_CITY',
        'MISSING_REQUIRED_FIELD',
        'ADDRESS_IN_CA_NAME_FIELD',
        'ADDRESS_IN_FIRM_NAME',
        'ADDRESS_IN_CITY_FIELD',
        'INVALID_PERSON_NAME',
        'INVALID_FIRM_NAME',
        'LOW_FIELD_CONFIDENCE',
    ],

    /*
    | Codes that must never affect this workflow even if present in audit data.
    */
    'ignored_decision_codes' => [
        'AMBIGUOUS_LAYOUT',
        'AMBIGUOUS_TABLE_STRUCTURE',
        'AMBIGUOUS_RECORD_BOUNDARY',
        'ORPHAN_TOKEN',
        'NUMERIC_FIELD_AMBIGUOUS',
        'SOURCE_COLUMN_MISMATCH',
        'PIN_IN_MEMBERSHIP_FIELD',
        'ADDRESS_IN_PARTNER_FIELD',
        'CROSS_COLUMN_CONTAMINATION',
        'FRN_FORMAT',
        'MEMBERSHIP_FORMAT',
        'CITY_BOUNDARY_UNCERTAIN',
        'CA_NAME_BOUNDARY_UNCERTAIN',
        'FIRM_NAME_BOUNDARY_UNCERTAIN',
        'ROW_MERGE_SUSPECTED',
        'ROW_SPLIT_SUSPECTED',
    ],
];
