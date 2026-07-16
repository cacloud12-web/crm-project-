<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ca_reference';

    public function up(): void
    {
        Schema::connection($this->connection)->create('ocr_import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('successful_records')->default(0);
            $table->unsignedInteger('failed_records')->default(0);
            $table->unsignedInteger('duplicate_records')->default(0);
            $table->unsignedInteger('processing_time')->nullable()->comment('Processing time in seconds');
            $table->timestamps();

            $table->index('uploaded_by');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('ocr_import_logs');
    }
};
