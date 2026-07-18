<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent repair for environments where ocr_parsed_* tables were created
 * before the richer staging schema landed (missing raw_/normalized_/source_data columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ocr_parsed_firms')) {
            Schema::table('ocr_parsed_firms', function (Blueprint $table) {
                if (! Schema::hasColumn('ocr_parsed_firms', 'raw_firm_name')) {
                    $table->string('raw_firm_name')->nullable()->after('sequence_no');
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'normalized_firm_name')) {
                    $table->string('normalized_firm_name')->nullable()->after('firm_name');
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'district')) {
                    $table->string('district', 120)->nullable()->after('city');
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'partner_count')) {
                    $table->unsignedInteger('partner_count')->nullable()->after('website');
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'matched_reference_firm_id')) {
                    $table->unsignedBigInteger('matched_reference_firm_id')->nullable()->after('page_number');
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'source_data')) {
                    $table->json('source_data')->nullable();
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'notes')) {
                    $table->text('notes')->nullable();
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'crm_ca_id')) {
                    $table->unsignedBigInteger('crm_ca_id')->nullable();
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'matched_ca_id')) {
                    $table->unsignedBigInteger('matched_ca_id')->nullable();
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'match_status')) {
                    $table->string('match_status', 40)->nullable();
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'match_confidence')) {
                    $table->decimal('match_confidence', 5, 4)->nullable();
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'match_reason')) {
                    $table->string('match_reason', 190)->nullable();
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'match_candidates')) {
                    $table->json('match_candidates')->nullable();
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'mapped_at')) {
                    $table->timestamp('mapped_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('ocr_parsed_members')) {
            Schema::table('ocr_parsed_members', function (Blueprint $table) {
                if (! Schema::hasColumn('ocr_parsed_members', 'raw_ca_name')) {
                    $table->string('raw_ca_name')->nullable()->after('sequence_no');
                }
                if (! Schema::hasColumn('ocr_parsed_members', 'normalized_ca_name')) {
                    $table->string('normalized_ca_name')->nullable()->after('ca_name');
                }
                if (! Schema::hasColumn('ocr_parsed_members', 'pan_no')) {
                    $table->string('pan_no', 20)->nullable()->after('email');
                }
                if (! Schema::hasColumn('ocr_parsed_members', 'is_primary')) {
                    $table->boolean('is_primary')->default(false)->after('role');
                }
                if (! Schema::hasColumn('ocr_parsed_members', 'page_number')) {
                    $table->unsignedInteger('page_number')->nullable()->after('overall_confidence');
                }
                if (! Schema::hasColumn('ocr_parsed_members', 'matched_reference_member_id')) {
                    $table->unsignedBigInteger('matched_reference_member_id')->nullable();
                }
                if (! Schema::hasColumn('ocr_parsed_members', 'review_status')) {
                    $table->string('review_status', 32)->default('pending');
                }
                if (! Schema::hasColumn('ocr_parsed_members', 'source_data')) {
                    $table->json('source_data')->nullable();
                }
                if (! Schema::hasColumn('ocr_parsed_members', 'notes')) {
                    $table->text('notes')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Non-destructive repair migration — no automatic column drops.
    }
};
