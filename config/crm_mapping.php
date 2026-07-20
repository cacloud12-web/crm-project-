<?php

/**
 * Master Data Mapping Engine — thresholds and batch behaviour.
 *
 * OCR / Excel / CSV / API all share MasterDataMatchingService + MasterDataMappingService.
 * Manual review is only for low-confidence or conflicting matches.
 */
return [

    /*
    | Auto-apply when a single exact identifier match is found (FRN/GST/mobile/email/PAN).
    */
    'auto_apply_exact' => filter_var(env('CRM_MAPPING_AUTO_APPLY_EXACT', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Auto-create a new ca_masters row when no candidates are found.
    */
    'auto_create_unmatched' => filter_var(env('CRM_MAPPING_AUTO_CREATE', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Minimum confidence to auto-update an existing Master record (0.0–1.0).
    | Exact identifier matches score 1.0. Normalized firm+city scores ~0.9.
    */
    'auto_update_min_confidence' => (float) env('CRM_MAPPING_AUTO_UPDATE_MIN', 0.90),

    /*
    | Scores at or above this but below auto_update_min go to manual review.
    | Below this threshold is treated as unmatched (create if enabled).
    */
    'review_min_confidence' => (float) env('CRM_MAPPING_REVIEW_MIN', 0.55),

    /*
    | Prefetch chunk size for indexed whereIn lookups (never full-table scan).
    */
    'index_chunk_size' => max(50, (int) env('CRM_MAPPING_INDEX_CHUNK', 500)),

    /*
    | Max fuzzy firm-name candidates to pull per prefix shortlist.
    */
    'fuzzy_prefix_limit' => max(5, (int) env('CRM_MAPPING_FUZZY_LIMIT', 25)),

    /*
    | Characters used for indexed prefix shortlist (normalized_firm_name LIKE 'xxxx%').
    */
    'fuzzy_prefix_length' => max(4, (int) env('CRM_MAPPING_FUZZY_PREFIX', 8)),

    /*
    | Queue mapping after OCR structure persist.
    */
    'queue_after_ocr_parse' => filter_var(env('CRM_MAPPING_QUEUE_AFTER_OCR', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Sync-map OCR documents (firm count at or below this) inline after parse.
    | Raised so Hostinger cron lag cannot leave cards Pending.
    */
    'sync_max_firms' => max(1, (int) env('CRM_MAPPING_SYNC_MAX_FIRMS', 50)),

    /*
    | Fuzzy name matches only auto-update at/above this score (requires strong support).
    */
    'fuzzy_auto_update_min' => (float) env('CRM_MAPPING_FUZZY_AUTO_MIN', 0.97),

    /*
    | Field-level OCR confidence below this forces Needs Review.
    */
    'field_confidence_review_min' => (float) env('CRM_MAPPING_FIELD_CONFIDENCE_MIN', 0.55),

    /*
    | Minimum overall field confidence required before auto-create/update Master Data.
    | Rows below this are staged for manual review — never silently saved.
    | Prefer OCR_MIN_REQUIRED_FIELD_CONFIDENCE (fail-closed default 0.99).
    */
    'auto_apply_field_confidence_min' => (float) env(
        'OCR_MIN_REQUIRED_FIELD_CONFIDENCE',
        env('CRM_MAPPING_AUTO_APPLY_FIELD_MIN', 0.99),
    ),

    /*
    | Mapping chunk size for large OCR documents (indexed batch match per chunk).
    */
    'map_chunk_size' => max(25, (int) env('CRM_MAPPING_MAP_CHUNK', 200)),

    /*
    | Route Excel/CSV importable rows through MasterDataMappingService::processBatch.
    */
    'use_engine_for_bulk' => filter_var(env('CRM_MAPPING_USE_ENGINE_FOR_BULK', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Default matching profile for processBatch.
    | identifier_first = OCR/Excel (FRN/GST/PAN/mobile…)
    | state_firm_ca    = sales-team imports against Master CA without mobiles
    */
    'default_matching_profile' => env('CRM_MAPPING_DEFAULT_PROFILE', 'identifier_first'),

    'profiles' => [
        'state_firm_ca' => [
            'auto_create_unmatched' => filter_var(env('CRM_MAPPING_SALES_AUTO_CREATE', false), FILTER_VALIDATE_BOOLEAN),
            'auto_update_min' => (float) env('CRM_MAPPING_SALES_AUTO_UPDATE_MIN', 0.90),
            'review_min' => (float) env('CRM_MAPPING_SALES_REVIEW_MIN', 0.70),
            'strong_ca_similarity' => (float) env('CRM_MAPPING_SALES_STRONG_CA', 0.88),
            'strong_firm_similarity' => (float) env('CRM_MAPPING_SALES_STRONG_FIRM', 0.88),
            'prefix_length' => max(4, (int) env('CRM_MAPPING_SALES_PREFIX', 8)),
            'prefix_limit' => max(5, (int) env('CRM_MAPPING_SALES_PREFIX_LIMIT', 25)),
            'weights' => [
                'firm_exact' => 0.40,
                'ca_exact' => 0.40,
                'firm_fuzzy' => 0.25,
                'ca_fuzzy' => 0.25,
                'city' => 0.05,
            ],
        ],
    ],

    /*
    | Max firms to import inline for Master CA OCR (larger files use ImportMasterCaOcrJob).
    | Keep this high enough for multi-page directory PDFs so imports finish without a queue worker.
    */
    'master_ca_sync_max_firms' => max(1, (int) env('CRM_MAPPING_MASTER_CA_SYNC_MAX', 25)),
    'master_ca_import_chunk' => max(25, (int) env('CRM_MAPPING_MASTER_CA_CHUNK', 200)),

    'source_types' => [
        'ocr' => 'OCR Import',
        'excel' => 'Excel Import',
        'csv' => 'CSV Import',
        'api' => 'API',
        'sales_team' => 'Sales Team Import',
        'master_ca' => 'Master CA Import',
        'manual' => 'Manual Review',
    ],

    'decisions' => [
        'auto_update',
        'auto_create',
        'needs_review',
        'conflict',
        'rejected',
        'skipped',
    ],
];
