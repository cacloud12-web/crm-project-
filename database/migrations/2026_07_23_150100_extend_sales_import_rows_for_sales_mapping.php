<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive columns on sales_import_rows for richer Sales List CSVs.
 * Existing rows remain valid; all new columns nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_import_rows')) {
            return;
        }

        Schema::table('sales_import_rows', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_import_rows', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->index()->after('employee_name');
            }
            if (! Schema::hasColumn('sales_import_rows', 'email')) {
                $table->string('email', 255)->nullable()->after('alternate_mobile_no');
            }
            if (! Schema::hasColumn('sales_import_rows', 'website')) {
                $table->string('website', 255)->nullable()->after('email');
            }
            if (! Schema::hasColumn('sales_import_rows', 'state_name')) {
                $table->string('state_name', 120)->nullable()->after('city_name');
            }
            if (! Schema::hasColumn('sales_import_rows', 'address')) {
                $table->text('address')->nullable()->after('state_name');
            }
            if (! Schema::hasColumn('sales_import_rows', 'pincode')) {
                $table->string('pincode', 20)->nullable()->after('address');
            }
            if (! Schema::hasColumn('sales_import_rows', 'call_status')) {
                $table->string('call_status', 120)->nullable()->after('remarks_2');
            }
            if (! Schema::hasColumn('sales_import_rows', 'follow_up')) {
                $table->string('follow_up', 255)->nullable()->after('call_status');
            }
            if (! Schema::hasColumn('sales_import_rows', 'software')) {
                $table->string('software', 120)->nullable()->after('follow_up');
            }
            if (! Schema::hasColumn('sales_import_rows', 'sales_source')) {
                $table->string('sales_source', 120)->nullable()->after('software');
            }
            if (! Schema::hasColumn('sales_import_rows', 'normalized_mobile')) {
                $table->string('normalized_mobile', 32)->nullable()->index()->after('normalized_city');
            }
            if (! Schema::hasColumn('sales_import_rows', 'normalized_email')) {
                $table->string('normalized_email', 255)->nullable()->index()->after('normalized_mobile');
            }
            if (! Schema::hasColumn('sales_import_rows', 'extra_columns')) {
                $table->json('extra_columns')->nullable()->after('raw_payload');
            }
            if (! Schema::hasColumn('sales_import_rows', 'confidence_tier')) {
                $table->string('confidence_tier', 40)->nullable()->index()->after('match_score');
            }
            if (! Schema::hasColumn('sales_import_rows', 'duplicate_of_row_id')) {
                $table->unsignedBigInteger('duplicate_of_row_id')->nullable()->index()->after('row_fingerprint');
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
            // SQLite cannot drop an indexed column until the index is removed.
            foreach ([
                'sales_import_rows_employee_id_index',
                'sales_import_rows_normalized_mobile_index',
                'sales_import_rows_normalized_email_index',
                'sales_import_rows_confidence_tier_index',
                'sales_import_rows_duplicate_of_row_id_index',
            ] as $index) {
                if (in_array($index, $indexes, true)) {
                    $table->dropIndex($index);
                }
            }
        });

        Schema::table('sales_import_rows', function (Blueprint $table) {
            $drops = [];
            foreach ([
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
            ] as $col) {
                if (Schema::hasColumn('sales_import_rows', $col)) {
                    $drops[] = $col;
                }
            }
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
