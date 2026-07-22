<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive fingerprint columns for safe multi-file / re-import duplicate prevention.
 * Do not run automatically — apply manually after backup + duplicate audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_import_rows')) {
            return;
        }

        Schema::table('sales_import_rows', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_import_rows', 'source_file_hash')) {
                $table->string('source_file_hash', 64)->nullable()->after('source_file_name')->index();
            }
            if (! Schema::hasColumn('sales_import_rows', 'row_fingerprint')) {
                $table->string('row_fingerprint', 64)->nullable()->after('source_file_hash');
            }
        });

        Schema::table('sales_import_rows', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('sales_import_rows'))->pluck('name')->all();

            if (! in_array('sales_import_source_file_name_index', $indexes, true)) {
                $table->index('source_file_name', 'sales_import_source_file_name_index');
            }
            if (! in_array('sales_import_batch_status_index', $indexes, true)) {
                $table->index(['import_batch_id', 'mapping_status'], 'sales_import_batch_status_index');
            }
            // Unique after verifying no duplicate fingerprints exist in production data.
            if (! in_array('sales_import_row_fingerprint_unique', $indexes, true)
                && Schema::hasColumn('sales_import_rows', 'row_fingerprint')) {
                $table->unique('row_fingerprint', 'sales_import_row_fingerprint_unique');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_import_rows')) {
            return;
        }

        Schema::table('sales_import_rows', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('sales_import_rows'))->pluck('name')->all();

            if (in_array('sales_import_row_fingerprint_unique', $indexes, true)) {
                $table->dropUnique('sales_import_row_fingerprint_unique');
            }
            if (in_array('sales_import_batch_status_index', $indexes, true)) {
                $table->dropIndex('sales_import_batch_status_index');
            }
            if (in_array('sales_import_source_file_name_index', $indexes, true)) {
                $table->dropIndex('sales_import_source_file_name_index');
            }
            $drops = [];
            if (Schema::hasColumn('sales_import_rows', 'row_fingerprint')) {
                $drops[] = 'row_fingerprint';
            }
            if (Schema::hasColumn('sales_import_rows', 'source_file_hash')) {
                $drops[] = 'source_file_hash';
            }
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
