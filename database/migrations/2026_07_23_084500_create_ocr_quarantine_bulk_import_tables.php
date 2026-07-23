<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quarantined OCR bulk-import tables (additive).
 * Do NOT run automatically on production without an explicit migrate step.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_quarantine_import_batches')) {
            Schema::create('ocr_quarantine_import_batches', function (Blueprint $table) {
                $table->id();
                $table->string('batch_id', 64)->unique();
                $table->string('status', 32)->default('dry_run')->index(); // dry_run|running|completed|rolled_back|failed
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->boolean('dry_run')->default(true);
                $table->unsignedInteger('chunk_size')->default(500);
                $table->unsignedBigInteger('last_ocr_parsed_firm_id')->nullable();
                $table->json('summary')->nullable();
                $table->json('backup_paths')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ocr_forced_review_candidates')) {
            Schema::create('ocr_forced_review_candidates', function (Blueprint $table) {
                $table->id();
                $table->string('batch_id', 64)->index();
                $table->unsignedBigInteger('ocr_parsed_firm_id')->index();
                $table->unsignedBigInteger('ocr_document_id')->nullable()->index();
                $table->unsignedInteger('source_row_number')->nullable();
                $table->string('firm_name', 512)->nullable();
                $table->string('ca_name', 512)->nullable();
                $table->string('city', 255)->nullable();
                $table->text('address')->nullable();
                $table->string('membership_no', 64)->nullable();
                $table->string('frn', 64)->nullable();
                $table->json('partners')->nullable();
                $table->json('original_ocr_payload')->nullable();
                $table->json('validation_problems')->nullable();
                $table->decimal('confidence_score', 8, 4)->nullable();
                $table->string('category', 64)->index();
                $table->string('disposition', 32)->default('quarantined')->index();
                // quarantined|eligible_for_master|imported|linked_existing|skipped|rejected|ignored|rolled_back
                $table->string('block_reason', 255)->nullable();
                $table->unsignedBigInteger('crm_ca_id')->nullable()->index();
                $table->boolean('master_created')->default(false);
                $table->boolean('master_overwritten')->default(false);
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['batch_id', 'ocr_parsed_firm_id'], 'ocr_frc_batch_firm_unique');
            });
        }

        if (! Schema::hasTable('ocr_quarantine_import_audits')) {
            Schema::create('ocr_quarantine_import_audits', function (Blueprint $table) {
                $table->id();
                $table->string('batch_id', 64)->index();
                $table->unsignedBigInteger('ocr_parsed_firm_id')->nullable()->index();
                $table->unsignedBigInteger('candidate_id')->nullable()->index();
                $table->string('action', 64)->index();
                $table->string('category', 64)->nullable();
                $table->string('disposition', 32)->nullable();
                $table->text('message')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->boolean('dry_run')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_quarantine_import_audits');
        Schema::dropIfExists('ocr_forced_review_candidates');
        Schema::dropIfExists('ocr_quarantine_import_batches');
    }
};
