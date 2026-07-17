<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Data Mapping Engine staging + audit columns.
 * Does not recreate ca_reference tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ca_masters') && ! Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                $table->string('normalized_firm_name')->nullable()->after('firm_name');
                $table->index('normalized_firm_name', 'ca_masters_normalized_firm_name_index');
            });
        }

        if (Schema::hasTable('ocr_parsed_firms')) {
            Schema::table('ocr_parsed_firms', function (Blueprint $table) {
                if (! Schema::hasColumn('ocr_parsed_firms', 'matched_ca_id')) {
                    $table->unsignedBigInteger('matched_ca_id')->nullable()->after('crm_ca_id');
                    $table->index('matched_ca_id');
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'match_status')) {
                    $table->string('match_status', 32)->nullable()->after('matched_ca_id');
                    $table->index('match_status');
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'match_confidence')) {
                    $table->decimal('match_confidence', 5, 4)->nullable()->after('match_status');
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'match_reason')) {
                    $table->string('match_reason', 80)->nullable()->after('match_confidence');
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'match_candidates')) {
                    $table->json('match_candidates')->nullable()->after('match_reason');
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'mapped_at')) {
                    $table->timestamp('mapped_at')->nullable()->after('match_candidates');
                }
            });
        }

        if (! Schema::hasTable('master_mapping_decisions')) {
            Schema::create('master_mapping_decisions', function (Blueprint $table) {
                $table->id();
                $table->string('source_type', 32);
                $table->string('source_ref', 120)->nullable();
                $table->unsignedBigInteger('staging_id')->nullable();
                $table->string('decision', 32);
                $table->unsignedBigInteger('matched_ca_id')->nullable();
                $table->decimal('confidence', 5, 4)->nullable();
                $table->string('matched_on', 80)->nullable();
                $table->json('candidates')->nullable();
                $table->json('payload_snapshot')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->index(['source_type', 'source_ref']);
                $table->index(['decision', 'created_at']);
                $table->index('matched_ca_id');
                $table->index('staging_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('master_mapping_decisions');

        if (Schema::hasTable('ocr_parsed_firms')) {
            Schema::table('ocr_parsed_firms', function (Blueprint $table) {
                foreach (['mapped_at', 'match_candidates', 'match_reason', 'match_confidence', 'match_status', 'matched_ca_id'] as $column) {
                    if (Schema::hasColumn('ocr_parsed_firms', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('ca_masters') && Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                $table->dropIndex('ca_masters_normalized_firm_name_index');
                $table->dropColumn('normalized_firm_name');
            });
        }
    }
};
