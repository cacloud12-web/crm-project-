<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ca_id')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->string('checksum', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->longText('extracted_text')->nullable();
            $table->longText('corrected_text')->nullable();
            $table->json('structured_data')->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->json('detected_languages')->nullable();
            $table->decimal('average_confidence', 5, 4)->nullable();
            $table->string('processor_name')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->nullOnDelete();
            $table->index('status');
            $table->index('uploaded_by');
            $table->index('ca_id');
            $table->index('created_at');
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_documents');
    }
};
