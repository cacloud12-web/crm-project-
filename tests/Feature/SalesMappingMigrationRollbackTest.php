<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Rollback must remove only Sales Mapping additions, then re-migrate restores them.
 * Invokes each migration down() explicitly (Laravel --step counts batches, not files).
 */
class SalesMappingMigrationRollbackTest extends TestCase
{
    /** @var list<string> */
    private const MIGRATION_FILES = [
        '2026_07_23_150000_extend_master_import_batches_for_sales_mapping.php',
        '2026_07_23_150100_extend_sales_import_rows_for_sales_mapping.php',
        '2026_07_23_150200_create_sales_master_links_table.php',
        '2026_07_23_150300_create_sales_contacts_table.php',
        '2026_07_23_150400_create_sales_histories_table.php',
        '2026_07_23_150500_create_sales_mapping_reviews_table.php',
    ];

    /** @var list<string> */
    private const NEW_TABLES = [
        'sales_master_links',
        'sales_contacts',
        'sales_histories',
        'sales_mapping_reviews',
    ];

    /** @var list<string> */
    private const NEW_BATCH_COLUMNS = [
        'employee_id',
        'matched_count',
        'rejected_count',
        'skipped_count',
        'unmatched_count',
        'processing_ms',
        'column_map',
        'started_at',
        'finished_at',
    ];

    /** @var list<string> */
    private const NEW_SALES_ROW_COLUMNS = [
        'employee_id',
        'email',
        'website',
        'state_name',
        'address',
        'pincode',
        'call_status',
        'follow_up',
        'software',
        'sales_source',
        'normalized_mobile',
        'normalized_email',
        'extra_columns',
        'confidence_tier',
        'duplicate_of_row_id',
    ];

    public function test_rollback_removes_only_sales_mapping_schema_then_migrate_restores(): void
    {
        if (! Schema::hasTable('master_import_batches') || ! Schema::hasTable('sales_import_rows')) {
            $this->markTestSkipped('Base import tables missing');
        }

        // Ensure our six migrations are applied before testing down().
        Artisan::call('migrate', ['--force' => true]);

        foreach (self::NEW_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Precondition: {$table} must exist");
        }

        $legacyMasterCols = Schema::getColumnListing('ca_masters');
        $legacyBatchCols = array_values(array_diff(
            Schema::getColumnListing('master_import_batches'),
            self::NEW_BATCH_COLUMNS
        ));
        $legacyRowCols = array_values(array_diff(
            Schema::getColumnListing('sales_import_rows'),
            self::NEW_SALES_ROW_COLUMNS
        ));

        foreach (array_reverse(self::MIGRATION_FILES) as $file) {
            $path = database_path('migrations/'.$file);
            $this->assertFileExists($path);
            $migration = require $path;
            $migration->down();
            DB::table('migrations')
                ->where('migration', pathinfo($file, PATHINFO_FILENAME))
                ->delete();
        }

        foreach (self::NEW_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "Rollback must drop {$table}");
        }
        foreach (self::NEW_BATCH_COLUMNS as $col) {
            $this->assertFalse(
                Schema::hasColumn('master_import_batches', $col),
                "Rollback must drop master_import_batches.{$col}"
            );
        }
        foreach (self::NEW_SALES_ROW_COLUMNS as $col) {
            $this->assertFalse(
                Schema::hasColumn('sales_import_rows', $col),
                "Rollback must drop sales_import_rows.{$col}"
            );
        }

        $this->assertTrue(Schema::hasTable('master_import_batches'));
        $this->assertTrue(Schema::hasTable('sales_import_rows'));
        $this->assertTrue(Schema::hasTable('ca_masters'));
        $this->assertTrue(Schema::hasColumn('master_import_batches', 'source_type'));
        $this->assertTrue(Schema::hasColumn('master_import_batches', 'review_count'));
        $this->assertTrue(Schema::hasColumn('sales_import_rows', 'mapping_status'));
        $this->assertTrue(Schema::hasColumn('sales_import_rows', 'matched_ca_id'));
        $this->assertTrue(Schema::hasColumn('sales_import_rows', 'raw_payload'));
        $this->assertSame(
            $legacyMasterCols,
            Schema::getColumnListing('ca_masters'),
            'ca_masters columns must be unchanged by Sales Mapping rollback'
        );

        Artisan::call('migrate', ['--force' => true]);

        foreach (self::NEW_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Remigrate must recreate {$table}");
        }
        foreach (self::NEW_BATCH_COLUMNS as $col) {
            $this->assertTrue(Schema::hasColumn('master_import_batches', $col));
        }
        foreach (self::NEW_SALES_ROW_COLUMNS as $col) {
            $this->assertTrue(Schema::hasColumn('sales_import_rows', $col));
        }
        foreach ($legacyBatchCols as $col) {
            $this->assertTrue(Schema::hasColumn('master_import_batches', $col));
        }
        foreach ($legacyRowCols as $col) {
            $this->assertTrue(Schema::hasColumn('sales_import_rows', $col));
        }
    }
}
