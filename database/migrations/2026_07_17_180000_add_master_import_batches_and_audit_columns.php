<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import batches (rollback), richer mapping audit, field confidence on masters.
 * Additive only — never wipes production data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_import_batches')) {
            Schema::create('master_import_batches', function (Blueprint $table) {
                $table->id();
                $table->string('source_type', 32);
                $table->string('source_ref', 120)->nullable()->index();
                $table->string('file_name')->nullable();
                $table->string('file_hash', 64)->nullable()->index();
                $table->string('status', 32)->default('processing')->index();
                $table->unsignedInteger('total_records')->default(0);
                $table->unsignedInteger('created_count')->default(0);
                $table->unsignedInteger('updated_count')->default(0);
                $table->unsignedInteger('duplicate_count')->default(0);
                $table->unsignedInteger('review_count')->default(0);
                $table->unsignedInteger('conflict_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->string('progress_stage', 40)->nullable();
                $table->unsignedTinyInteger('progress_pct')->default(0);
                $table->json('created_ca_ids')->nullable();
                $table->json('updated_snapshots')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->timestamp('rolled_back_at')->nullable();
                $table->unsignedBigInteger('rolled_back_by')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('master_mapping_decisions')) {
            Schema::table('master_mapping_decisions', function (Blueprint $table) {
                if (! Schema::hasColumn('master_mapping_decisions', 'import_batch_id')) {
                    $table->unsignedBigInteger('import_batch_id')->nullable()->after('id')->index();
                }
                if (! Schema::hasColumn('master_mapping_decisions', 'old_values')) {
                    $table->json('old_values')->nullable()->after('payload_snapshot');
                }
                if (! Schema::hasColumn('master_mapping_decisions', 'new_values')) {
                    $table->json('new_values')->nullable()->after('old_values');
                }
            });
        }

        if (Schema::hasTable('ca_masters') && ! Schema::hasColumn('ca_masters', 'field_confidence')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                $table->json('field_confidence')->nullable()->after('rating');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('master_mapping_decisions')) {
            Schema::table('master_mapping_decisions', function (Blueprint $table) {
                foreach (['new_values', 'old_values', 'import_batch_id'] as $column) {
                    if (Schema::hasColumn('master_mapping_decisions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('ca_masters') && Schema::hasColumn('ca_masters', 'field_confidence')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                $table->dropColumn('field_confidence');
            });
        }

        Schema::dropIfExists('master_import_batches');
    }
};
