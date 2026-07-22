<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_import_rows', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_import_rows', 'matched_reference_firm_id')) {
                $table->unsignedBigInteger('matched_reference_firm_id')->nullable()->index()->after('matched_ca_id');
            }
            if (! Schema::hasColumn('sales_import_rows', 'match_candidates')) {
                $table->json('match_candidates')->nullable()->after('review_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_import_rows', function (Blueprint $table) {
            if (Schema::hasColumn('sales_import_rows', 'match_candidates')) {
                $table->dropColumn('match_candidates');
            }
            if (Schema::hasColumn('sales_import_rows', 'matched_reference_firm_id')) {
                $table->dropColumn('matched_reference_firm_id');
            }
        });
    }
};
