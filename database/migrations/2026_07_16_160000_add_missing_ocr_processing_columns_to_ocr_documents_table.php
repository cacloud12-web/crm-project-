<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safe catch-up for older ocr_documents schemas.
 * Adds only missing columns; never drops or renames existing data columns.
 *
 * Earlier OCR migrations may already add many of these fields. hasColumn guards
 * make this migration idempotent when run after those migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_documents')) {
            return;
        }

        Schema::table('ocr_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_documents', 'provider')) {
                $table->string('provider', 64)->default('google_document_ai')->after('status');
            }
            if (! Schema::hasColumn('ocr_documents', 'processing_mode')) {
                $table->string('processing_mode', 16)->nullable()->after('provider');
            }
            if (! Schema::hasColumn('ocr_documents', 'provider_reference')) {
                $table->string('provider_reference')->nullable()->after('processing_mode');
            }
            if (! Schema::hasColumn('ocr_documents', 'provider_operation_name')) {
                $table->string('provider_operation_name', 512)->nullable()->after('provider_reference');
            }
            if (! Schema::hasColumn('ocr_documents', 'gcs_input_uri')) {
                $table->string('gcs_input_uri', 1024)->nullable()->after('provider_operation_name');
            }
            if (! Schema::hasColumn('ocr_documents', 'gcs_output_uri')) {
                $table->string('gcs_output_uri', 1024)->nullable()->after('gcs_input_uri');
            }
            if (! Schema::hasColumn('ocr_documents', 'processing_progress')) {
                $table->string('processing_progress', 255)->nullable()->after('gcs_output_uri');
            }
            if (! Schema::hasColumn('ocr_documents', 'result_checksum')) {
                $table->string('result_checksum', 64)->nullable()->after('checksum');
            }
            if (! Schema::hasColumn('ocr_documents', 'total_pages')) {
                $table->unsignedInteger('total_pages')->nullable()->after('page_count');
            }
            if (! Schema::hasColumn('ocr_documents', 'processed_pages')) {
                $table->unsignedInteger('processed_pages')->nullable()->after('total_pages');
            }
            if (! Schema::hasColumn('ocr_documents', 'import_batch_id')) {
                $table->unsignedBigInteger('import_batch_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('ocr_documents', 'corrected_by')) {
                $table->unsignedBigInteger('corrected_by')->nullable()->after('corrected_text');
            }
            if (! Schema::hasColumn('ocr_documents', 'corrected_at')) {
                $table->timestamp('corrected_at')->nullable()->after('corrected_by');
            }
            if (! Schema::hasColumn('ocr_documents', 'batch_started_at')) {
                $table->timestamp('batch_started_at')->nullable()->after('processing_started_at');
            }
            if (! Schema::hasColumn('ocr_documents', 'batch_completed_at')) {
                $table->timestamp('batch_completed_at')->nullable()->after('batch_started_at');
            }
            if (! Schema::hasColumn('ocr_documents', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('processed_at');
            }
            if (! Schema::hasColumn('ocr_documents', 'parse_status')) {
                $table->string('parse_status', 32)->nullable()->after('status');
            }
            if (! Schema::hasColumn('ocr_documents', 'parsed_firm_count')) {
                $table->unsignedInteger('parsed_firm_count')->nullable()->after('parse_status');
            }
            if (! Schema::hasColumn('ocr_documents', 'parsed_at')) {
                $table->timestamp('parsed_at')->nullable()->after('parsed_firm_count');
            }
        });

        Schema::table('ocr_documents', function (Blueprint $table) {
            if (Schema::hasColumn('ocr_documents', 'provider') && ! $this->indexExists('ocr_documents_provider_index')) {
                $table->index('provider', 'ocr_documents_provider_index');
            }
            if (Schema::hasColumn('ocr_documents', 'processing_mode') && ! $this->indexExists('ocr_documents_processing_mode_index')) {
                $table->index('processing_mode', 'ocr_documents_processing_mode_index');
            }
            if (
                Schema::hasColumn('ocr_documents', 'status')
                && Schema::hasColumn('ocr_documents', 'processing_mode')
                && ! $this->indexExists('ocr_documents_status_processing_mode_index')
            ) {
                $table->index(['status', 'processing_mode'], 'ocr_documents_status_processing_mode_index');
            }
            if (Schema::hasColumn('ocr_documents', 'processed_at') && ! $this->indexExists('ocr_documents_processed_at_index')) {
                $table->index('processed_at', 'ocr_documents_processed_at_index');
            }
            if (Schema::hasColumn('ocr_documents', 'failed_at') && ! $this->indexExists('ocr_documents_failed_at_index')) {
                $table->index('failed_at', 'ocr_documents_failed_at_index');
            }
            if (Schema::hasColumn('ocr_documents', 'import_batch_id') && ! $this->indexExists('ocr_documents_import_batch_id_index')) {
                $table->index('import_batch_id', 'ocr_documents_import_batch_id_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ocr_documents')) {
            return;
        }

        // Only roll back columns uniquely introduced by this catch-up migration.
        // Shared columns (provider, processing_mode, …) are owned by earlier migrations.
        Schema::table('ocr_documents', function (Blueprint $table) {
            if ($this->indexExists('ocr_documents_failed_at_index')) {
                $table->dropIndex('ocr_documents_failed_at_index');
            }
            if ($this->indexExists('ocr_documents_processed_at_index')) {
                $table->dropIndex('ocr_documents_processed_at_index');
            }
            if ($this->indexExists('ocr_documents_provider_index')) {
                $table->dropIndex('ocr_documents_provider_index');
            }
            if (Schema::hasColumn('ocr_documents', 'failed_at')) {
                $table->dropColumn('failed_at');
            }
        });
    }

    private function indexExists(string $indexName): bool
    {
        try {
            return Schema::hasIndex('ocr_documents', $indexName);
        } catch (\Throwable) {
            return false;
        }
    }
};
