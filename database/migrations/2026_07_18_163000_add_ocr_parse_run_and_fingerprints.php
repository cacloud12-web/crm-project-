<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms')) {
            return;
        }

        Schema::table('ocr_parsed_firms', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_parsed_firms', 'parse_run_id')) {
                $table->string('parse_run_id', 64)->nullable()->after('ocr_document_id')->index();
            }
            if (! Schema::hasColumn('ocr_parsed_firms', 'source_fingerprint')) {
                $table->string('source_fingerprint', 64)->nullable()->after('parse_run_id');
            }
            if (! Schema::hasColumn('ocr_parsed_firms', 'business_fingerprint')) {
                $table->string('business_fingerprint', 64)->nullable()->after('source_fingerprint');
            }
            if (! Schema::hasColumn('ocr_parsed_firms', 'column_number')) {
                $table->unsignedSmallInteger('column_number')->nullable()->after('page_number');
            }
            if (! Schema::hasColumn('ocr_parsed_firms', 'is_noise')) {
                $table->boolean('is_noise')->default(false)->after('validation_errors');
            }
        });

        Schema::table('ocr_parsed_firms', function (Blueprint $table) {
            $indexes = Schema::getIndexes('ocr_parsed_firms');
            $names = array_column($indexes, 'name');
            if (! in_array('ocr_parsed_firms_doc_source_fp_unique', $names, true)
                && Schema::hasColumn('ocr_parsed_firms', 'source_fingerprint')) {
                $table->unique(['ocr_document_id', 'source_fingerprint'], 'ocr_parsed_firms_doc_source_fp_unique');
            }
        });

        if (Schema::hasTable('ocr_documents') && ! Schema::hasColumn('ocr_documents', 'active_parse_run_id')) {
            Schema::table('ocr_documents', function (Blueprint $table) {
                $table->string('active_parse_run_id', 64)->nullable()->after('parsed_firm_count');
                $table->unsignedInteger('valid_firm_count')->nullable()->after('active_parse_run_id');
                $table->unsignedInteger('candidate_firm_count')->nullable()->after('valid_firm_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ocr_parsed_firms')) {
            Schema::table('ocr_parsed_firms', function (Blueprint $table) {
                $indexes = Schema::getIndexes('ocr_parsed_firms');
                $names = array_column($indexes, 'name');
                if (in_array('ocr_parsed_firms_doc_source_fp_unique', $names, true)) {
                    $table->dropUnique('ocr_parsed_firms_doc_source_fp_unique');
                }
                foreach (['parse_run_id', 'source_fingerprint', 'business_fingerprint', 'column_number', 'is_noise'] as $col) {
                    if (Schema::hasColumn('ocr_parsed_firms', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        if (Schema::hasTable('ocr_documents')) {
            Schema::table('ocr_documents', function (Blueprint $table) {
                foreach (['active_parse_run_id', 'valid_firm_count', 'candidate_firm_count'] as $col) {
                    if (Schema::hasColumn('ocr_documents', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
