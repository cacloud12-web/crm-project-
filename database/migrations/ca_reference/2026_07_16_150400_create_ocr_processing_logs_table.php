<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ca_reference';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('ocr_processing_logs')) {
            return;
        }

        Schema::connection($this->connection)->create('ocr_processing_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_log_id')->constrained('ocr_import_logs')->cascadeOnDelete();
            $table->longText('raw_text')->nullable();
            $table->json('structured_json')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['import_log_id', 'status']);
            $table->index('confidence');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('ocr_processing_logs');
    }
};
