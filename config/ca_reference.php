<?php

return [
    /*
    | Dedicated CA reference migrations live ONLY under this path.
    | Always migrate with:
    |   php artisan migrate --database=ca_reference --path=database/migrations/ca_reference --force
    */
    'migrations_path' => 'database/migrations/ca_reference',

    /*
    | Tables that must never be dropped from the ca_reference database.
    */
    'keep_tables' => [
        'migrations',
        'ca_firms',
        'ca_partners',
        'ca_addresses',
        'mapping_logs',
        'ocr_import_logs',
        'ocr_processing_logs',
        'ca_reference_import_batches',
        'ca_reference_import_rows',
    ],

    /*
    | Migration names that must remain in ca_reference.migrations.
    | Everything else recorded there is treated as accidental CRM noise.
    */
    'keep_migrations' => [
        '2026_07_16_150000_create_ca_firms_table',
        '2026_07_16_150100_create_ca_partners_table',
        '2026_07_16_150200_create_ca_addresses_table',
        '2026_07_16_150300_create_ocr_import_logs_table',
        '2026_07_16_150400_create_ocr_processing_logs_table',
        '2026_07_16_150500_create_mapping_logs_table',
        '2026_07_17_230000_add_ca_reference_normalized_and_import_tables',
    ],

    /*
    | Prefixes that are always treated as dedicated reference tables (keep).
    */
    'keep_table_prefixes' => [
        'ca_reference_import_',
    ],
];
