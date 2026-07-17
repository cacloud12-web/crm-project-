<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_documents')) {
            return;
        }

        Schema::table('ocr_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_documents', 'processing_mode')) {
                $table->string('processing_mode', 16)->nullable()->after('provider');
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
            if (! Schema::hasColumn('ocr_documents', 'total_pages')) {
                $table->unsignedInteger('total_pages')->nullable()->after('page_count');
            }
            if (! Schema::hasColumn('ocr_documents', 'processed_pages')) {
                $table->unsignedInteger('processed_pages')->nullable()->after('total_pages');
            }
            if (! Schema::hasColumn('ocr_documents', 'result_checksum')) {
                $table->string('result_checksum', 64)->nullable()->after('checksum');
            }
            if (! Schema::hasColumn('ocr_documents', 'batch_started_at')) {
                $table->timestamp('batch_started_at')->nullable()->after('processing_started_at');
            }
            if (! Schema::hasColumn('ocr_documents', 'batch_completed_at')) {
                $table->timestamp('batch_completed_at')->nullable()->after('batch_started_at');
            }
        });

        Schema::table('ocr_documents', function (Blueprint $table) {
            if (Schema::hasColumn('ocr_documents', 'processing_mode') && ! $this->indexExists('ocr_documents_processing_mode_index')) {
                $table->index('processing_mode', 'ocr_documents_processing_mode_index');
            }
            if (Schema::hasColumn('ocr_documents', 'provider_operation_name') && ! $this->indexExists('ocr_documents_provider_operation_name_index')) {
                $table->index('provider_operation_name', 'ocr_documents_provider_operation_name_index');
            }
            if (
                Schema::hasColumn('ocr_documents', 'status')
                && Schema::hasColumn('ocr_documents', 'processing_mode')
                && ! $this->indexExists('ocr_documents_status_processing_mode_index')
            ) {
                $table->index(['status', 'processing_mode'], 'ocr_documents_status_processing_mode_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ocr_documents')) {
            return;
        }

        Schema::table('ocr_documents', function (Blueprint $table) {
            foreach ([
                'ocr_documents_processing_mode_index',
                'ocr_documents_provider_operation_name_index',
                'ocr_documents_status_processing_mode_index',
            ] as $index) {
                if ($this->indexExists($index)) {
                    $table->dropIndex($index);
                }
            }

            foreach ([
                'processing_mode',
                'provider_operation_name',
                'gcs_input_uri',
                'gcs_output_uri',
                'processing_progress',
                'total_pages',
                'processed_pages',
                'result_checksum',
                'batch_started_at',
                'batch_completed_at',
            ] as $column) {
                if (Schema::hasColumn('ocr_documents', $column)) {
                    $table->dropColumn($column);
                }
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
