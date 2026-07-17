<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for OCR staging review filters and Master mapping lookups.
 * Additive only — does not wipe data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ocr_parsed_firms')) {
            Schema::table('ocr_parsed_firms', function (Blueprint $table) {
                $indexes = collect(Schema::getIndexes('ocr_parsed_firms'))->pluck('name')->all();
                if (! in_array('ocr_parsed_firms_doc_match_review_index', $indexes, true)
                    && Schema::hasColumn('ocr_parsed_firms', 'match_status')
                    && Schema::hasColumn('ocr_parsed_firms', 'review_status')) {
                    $table->index(
                        ['ocr_document_id', 'match_status', 'review_status'],
                        'ocr_parsed_firms_doc_match_review_index',
                    );
                }
                if (! in_array('ocr_parsed_firms_normalized_firm_name_index', $indexes, true)
                    && Schema::hasColumn('ocr_parsed_firms', 'normalized_firm_name')) {
                    $table->index('normalized_firm_name', 'ocr_parsed_firms_normalized_firm_name_index');
                }
            });
        }

        if (Schema::hasTable('ca_masters') && Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                $indexes = collect(Schema::getIndexes('ca_masters'))->pluck('name')->all();
                if (! in_array('ca_masters_normalized_firm_name_index', $indexes, true)) {
                    $table->index('normalized_firm_name', 'ca_masters_normalized_firm_name_index');
                }
            });
        }

        if (Schema::hasTable('master_mapping_decisions')) {
            Schema::table('master_mapping_decisions', function (Blueprint $table) {
                $indexes = collect(Schema::getIndexes('master_mapping_decisions'))->pluck('name')->all();
                if (! in_array('master_mapping_decisions_source_ref_index', $indexes, true)) {
                    $table->index(['source_type', 'source_ref'], 'master_mapping_decisions_source_ref_index');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ocr_parsed_firms')) {
            Schema::table('ocr_parsed_firms', function (Blueprint $table) {
                $indexes = collect(Schema::getIndexes('ocr_parsed_firms'))->pluck('name')->all();
                foreach (['ocr_parsed_firms_doc_match_review_index', 'ocr_parsed_firms_normalized_firm_name_index'] as $name) {
                    if (in_array($name, $indexes, true)) {
                        $table->dropIndex($name);
                    }
                }
            });
        }

        if (Schema::hasTable('ca_masters')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                $indexes = collect(Schema::getIndexes('ca_masters'))->pluck('name')->all();
                if (in_array('ca_masters_normalized_firm_name_index', $indexes, true)) {
                    $table->dropIndex('ca_masters_normalized_firm_name_index');
                }
            });
        }

        if (Schema::hasTable('master_mapping_decisions')) {
            Schema::table('master_mapping_decisions', function (Blueprint $table) {
                $indexes = collect(Schema::getIndexes('master_mapping_decisions'))->pluck('name')->all();
                if (in_array('master_mapping_decisions_source_ref_index', $indexes, true)) {
                    $table->dropIndex('master_mapping_decisions_source_ref_index');
                }
            });
        }
    }
};
