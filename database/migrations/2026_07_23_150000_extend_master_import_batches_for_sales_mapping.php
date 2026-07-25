<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Sales Mapping counters/metadata on master_import_batches.
 * Reuses existing review_count — does not rename or wipe existing batch rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_import_batches')) {
            return;
        }

        Schema::table('master_import_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('master_import_batches', 'employee_id')) {
                // employees.employee_id (not users.id)
                $table->unsignedBigInteger('employee_id')->nullable()->index()->after('actor_id');
            }
            if (! Schema::hasColumn('master_import_batches', 'matched_count')) {
                $table->unsignedInteger('matched_count')->default(0)->after('duplicate_count');
            }
            if (! Schema::hasColumn('master_import_batches', 'rejected_count')) {
                $table->unsignedInteger('rejected_count')->default(0)->after('review_count');
            }
            if (! Schema::hasColumn('master_import_batches', 'skipped_count')) {
                $table->unsignedInteger('skipped_count')->default(0)->after('rejected_count');
            }
            if (! Schema::hasColumn('master_import_batches', 'unmatched_count')) {
                $table->unsignedInteger('unmatched_count')->default(0)->after('skipped_count');
            }
            if (! Schema::hasColumn('master_import_batches', 'processing_ms')) {
                $table->unsignedInteger('processing_ms')->nullable()->after('progress_pct');
            }
            if (! Schema::hasColumn('master_import_batches', 'column_map')) {
                $table->json('column_map')->nullable()->after('remarks');
            }
            if (! Schema::hasColumn('master_import_batches', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('column_map');
            }
            if (! Schema::hasColumn('master_import_batches', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->after('started_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('master_import_batches')) {
            return;
        }

        Schema::table('master_import_batches', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('master_import_batches'))->pluck('name')->all();
            // SQLite cannot drop an indexed column until the index is removed.
            if (in_array('master_import_batches_employee_id_index', $indexes, true)) {
                $table->dropIndex('master_import_batches_employee_id_index');
            }
        });

        Schema::table('master_import_batches', function (Blueprint $table) {
            $drops = [];
            foreach ([
                'employee_id',
                'matched_count',
                'rejected_count',
                'skipped_count',
                'unmatched_count',
                'processing_ms',
                'column_map',
                'started_at',
                'finished_at',
            ] as $col) {
                if (Schema::hasColumn('master_import_batches', $col)) {
                    $drops[] = $col;
                }
            }
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
