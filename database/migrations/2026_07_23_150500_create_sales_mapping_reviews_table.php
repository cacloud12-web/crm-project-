<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual Sales Mapping review queue.
 * Default status = pending. Never auto-approved by schema/defaults.
 * Unmatched rows live here — never create ca_masters from this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_mapping_reviews')) {
            return;
        }

        Schema::create('sales_mapping_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_batch_id')->index();
            $table->unsignedBigInteger('sales_import_row_id');
            $table->json('candidate_ca_ids')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('match_tier', 40)->nullable();
            $table->string('reason', 64)->nullable()->index();
            // string status — no DB enum. Default pending only.
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('approved_ca_id')->nullable()->index();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->unique('sales_import_row_id', 'sales_mapping_reviews_row_unique');
            $table->index(['status', 'import_batch_id'], 'sales_mapping_reviews_status_batch_index');
            $table->index(['status', 'created_at'], 'sales_mapping_reviews_status_created_index');
        });

        if (Schema::hasTable('master_import_batches')) {
            Schema::table('sales_mapping_reviews', function (Blueprint $table) {
                $table->foreign('import_batch_id', 'sales_mapping_reviews_batch_fk')
                    ->references('id')->on('master_import_batches');
            });
        }
        if (Schema::hasTable('sales_import_rows')) {
            Schema::table('sales_mapping_reviews', function (Blueprint $table) {
                $table->foreign('sales_import_row_id', 'sales_mapping_reviews_row_fk')
                    ->references('id')->on('sales_import_rows');
            });
        }
        if (Schema::hasTable('ca_masters')) {
            Schema::table('sales_mapping_reviews', function (Blueprint $table) {
                $table->foreign('approved_ca_id', 'sales_mapping_reviews_approved_ca_fk')
                    ->references('ca_id')->on('ca_masters')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_mapping_reviews');
    }
};
