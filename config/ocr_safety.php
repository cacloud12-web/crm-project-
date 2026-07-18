<?php

/**
 * Fail-closed OCR safety policy.
 *
 * OCR is untrusted. No unverified row may modify Master Data.
 * Thresholds are configurable; production defaults are strict.
 */
return [

    /*
    | When true, OCR rows never auto-create Master records.
    */
    'auto_create' => filter_var(env('OCR_AUTO_CREATE', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | When true, OCR rows never auto-update Master records.
    */
    'auto_update' => filter_var(env('OCR_AUTO_UPDATE', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Every OCR row requires explicit verification before Master write.
    */
    'require_verification' => filter_var(env('OCR_REQUIRE_VERIFICATION', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Reject (quarantine) rows with cross-field collisions.
    */
    'reject_on_field_collision' => filter_var(env('OCR_REJECT_ON_FIELD_COLLISION', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Reject rows with ambiguous table / row boundaries.
    */
    'reject_on_row_ambiguity' => filter_var(env('OCR_REJECT_ON_ROW_AMBIGUITY', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Minimum confidence for every required field before auto-approval is allowed.
    | Manual Approve may still proceed after human review.
    */
    'min_required_field_confidence' => (float) env('OCR_MIN_REQUIRED_FIELD_CONFIDENCE', 0.99),

    /*
    | Parser/structural confidence thresholds (separate from raw OCR %).
    | Rows pass review when structure + validation are sound even if OCR is 92–98%.
    */
    'min_parser_confidence' => (float) env('OCR_MIN_PARSER_CONFIDENCE', 0.70),
    'min_structural_confidence' => (float) env('OCR_MIN_STRUCTURAL_CONFIDENCE', 0.80),
    'min_ocr_confidence_for_review_flag' => (float) env('OCR_MIN_OCR_CONFIDENCE_REVIEW', 0.55),

    /*
    | When true, LOW_FIELD_CONFIDENCE is only raised for auto-apply — not as a collision
    | when parser + structural confidence pass and validation is clean.
    */
    'low_confidence_blocks_auto_only' => filter_var(env('OCR_LOW_CONFIDENCE_BLOCKS_AUTO_ONLY', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Required fields that must be present + pass validation for auto-approval.
    */
    'required_fields_for_auto' => [
        'firm_name',
    ],

    /*
    | Disable fuzzy OCR auto-apply entirely when verification is required.
    */
    'allow_fuzzy_auto_apply' => filter_var(env('OCR_ALLOW_FUZZY_AUTO_APPLY', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Block bulk "Approve All Safe" when verification is required.
    */
    'allow_bulk_approve_safe' => filter_var(env('OCR_ALLOW_BULK_APPROVE_SAFE', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Collision error codes (stored on staging for review UI).
    */
    'collision_codes' => [
        'ADDRESS_IN_PARTNER_FIELD',
        'ADDRESS_IN_CA_NAME',
        'ADDRESS_IN_CA_NAME_FIELD',
        'ADDRESS_IN_FIRM_NAME',
        'PIN_IN_MEMBERSHIP_FIELD',
        'PIN_IN_FIRM_NAME',
        'MOBILE_IN_FIRM_NAME',
        'MOBILE_IN_CA_NAME',
        'MOBILE_IN_MEMBERSHIP_FIELD',
        'FRN_IN_WRONG_FIELD',
        'INVALID_PERSON_NAME',
        'INVALID_FIRM_NAME',
        'DUPLICATE_CA_AS_PARTNER',
        'CROSS_COLUMN_CONTAMINATION',
        'ORPHAN_TOKEN',
        'AMBIGUOUS_RECORD_BOUNDARY',
        'MEMBERSHIP_IN_PIN_FIELD',
        'FRN_POSITION_MISMATCH',
        'NUMERIC_FIELD_AMBIGUOUS',
        'ROW_MERGE_SUSPECTED',
        'ROW_SPLIT_SUSPECTED',
        'LOW_FIELD_CONFIDENCE',
        'SOURCE_COLUMN_MISMATCH',
        'MISSING_REQUIRED_FIELD',
        'AMBIGUOUS_TABLE_STRUCTURE',
        'AMBIGUOUS_LAYOUT',
        'INCOMPATIBLE_FIELD_OVERLAP',
    ],
];
