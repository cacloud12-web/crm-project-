<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_documents', function (Blueprint $table) {
            $table->string('processing_mode', 16)->nullable()->after('provider');
            $table->string('provider_operation_name', 512)->nullable()->after('provider_reference');
            $table->string('gcs_input_uri', 1024)->nullable()->after('provider_operation_name');
            $table->string('gcs_output_uri', 1024)->nullable()->after('gcs_input_uri');
            $table->string('processing_progress', 255)->nullable()->after('gcs_output_uri');
            $table->unsignedInteger('total_pages')->nullable()->after('page_count');
            $table->unsignedInteger('processed_pages')->nullable()->after('total_pages');
            $table->string('result_checksum', 64)->nullable()->after('checksum');
            $table->timestamp('batch_started_at')->nullable()->after('processing_started_at');
            $table->timestamp('batch_completed_at')->nullable()->after('batch_started_at');

            $table->index('processing_mode');
            $table->index('provider_operation_name');
            $table->index(['status', 'processing_mode']);
        });
    }

    public function down(): void
    {
        Schema::table('ocr_documents', function (Blueprint $table) {
            $table->dropIndex(['processing_mode']);
            $table->dropIndex(['provider_operation_name']);
            $table->dropIndex(['status', 'processing_mode']);

            $table->dropColumn([
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
            ]);
        });
    }
};
