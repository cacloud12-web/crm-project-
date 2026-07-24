<?php

/**
 * Production-safe locality → city mappings for ocr:repair-required-master-fields.
 *
 * Rules:
 * - Keys are lowercase OCR locality / city text (whitespace collapsed).
 * - Values are cities.city_name (preferred) or an integer city_id.
 * - At runtime each alias MUST resolve to exactly one city_id — otherwise it is ignored.
 * - Do not add guessed mappings. Only reviewed 1:1 aliases belong here.
 */
return [

    /*
    | Recovery snapshot table (ca_id list). Only these Masters are eligible for repair.
    */
    'recovery_table' => env('OCR_REPAIR_RECOVERY_TABLE', 'ca_masters_recovery_20260723'),

    /*
    | Locality aliases → canonical city_name or city_id.
    | Empty by default — populate only after human review.
    */
    'aliases' => [
        // Example (disabled until reviewed):
        // 'miraroad' => 'Thane',
        // 'savedi' => 'Ahmednagar',
    ],
];
