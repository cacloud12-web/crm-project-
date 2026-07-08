<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('import_duplicate_logs')) {
            return;
        }

        Schema::create('import_duplicate_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bulk_action_id')->nullable()->index();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->string('file_name', 255)->nullable();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('duplicate_value', 255)->nullable();
            $table->string('duplicate_type', 50)->nullable()->index();
            $table->unsignedBigInteger('matched_lead_id')->nullable()->index();
            $table->string('action_taken', 40)->default('skip')->index();
            $table->string('ca_name', 255)->nullable();
            $table->string('firm_name', 255)->nullable();
            $table->string('mobile_no', 30)->nullable();
            $table->string('email_id', 255)->nullable();
            $table->string('source', 40)->default('file')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_duplicate_logs');
    }
};
