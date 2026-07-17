<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an approved OCR staging firm to the CRM ca_masters row created on Approve.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms')) {
            return;
        }

        Schema::table('ocr_parsed_firms', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_parsed_firms', 'crm_ca_id')) {
                $table->unsignedBigInteger('crm_ca_id')->nullable()->after('matched_reference_firm_id');
                $table->index('crm_ca_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms') || ! Schema::hasColumn('ocr_parsed_firms', 'crm_ca_id')) {
            return;
        }

        Schema::table('ocr_parsed_firms', function (Blueprint $table) {
            $table->dropIndex(['crm_ca_id']);
            $table->dropColumn('crm_ca_id');
        });
    }
};
