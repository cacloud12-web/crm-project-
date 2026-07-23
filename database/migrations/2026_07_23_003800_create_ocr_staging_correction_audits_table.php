<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive audit table for OCR staging reprocess corrections.
 * Does not alter ocr_parsed_firms or ca_masters.
 *
 * Do NOT run automatically on production without an explicit migrate step.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ocr_staging_correction_audits')) {
            return;
        }

        Schema::create('ocr_staging_correction_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ocr_parsed_firm_id')->index();
            $table->unsignedBigInteger('ocr_document_id')->nullable()->index();
            $table->string('category', 64)->index();
            $table->json('raw_values')->nullable();
            $table->json('old_parsed_values')->nullable();
            $table->json('new_parsed_values')->nullable();
            $table->string('old_review_status', 32)->nullable();
            $table->string('new_review_status', 32)->nullable();
            $table->string('old_match_status', 64)->nullable();
            $table->string('new_match_status', 64)->nullable();
            $table->string('correction_reason', 255)->nullable();
            $table->decimal('confidence', 8, 4)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->boolean('dry_run')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_staging_correction_audits');
    }
};
