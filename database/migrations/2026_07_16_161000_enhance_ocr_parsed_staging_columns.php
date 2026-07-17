<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive staging-column enhancements for environments that already created
 * ocr_parsed_firms / ocr_parsed_members before the richer schema landed.
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
                    $table->json('source_data')->nullable()->after('matched_reference_firm_id');
                }
                if (! Schema::hasColumn('ocr_parsed_firms', 'notes')) {
                    $table->text('notes')->nullable()->after('source_data');
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
                    $table->unsignedBigInteger('matched_reference_member_id')->nullable()->after('page_number');
                }
                if (! Schema::hasColumn('ocr_parsed_members', 'review_status')) {
                    $table->string('review_status', 32)->default('pending')->after('matched_reference_member_id');
                }
                if (! Schema::hasColumn('ocr_parsed_members', 'source_data')) {
                    $table->json('source_data')->nullable()->after('review_status');
                }
                if (! Schema::hasColumn('ocr_parsed_members', 'notes')) {
                    $table->text('notes')->nullable()->after('source_data');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ocr_parsed_firms')) {
            Schema::table('ocr_parsed_firms', function (Blueprint $table) {
                foreach ([
                    'raw_firm_name', 'normalized_firm_name', 'district', 'partner_count',
                    'matched_reference_firm_id', 'source_data', 'notes',
                ] as $column) {
                    if (Schema::hasColumn('ocr_parsed_firms', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('ocr_parsed_members')) {
            Schema::table('ocr_parsed_members', function (Blueprint $table) {
                foreach ([
                    'raw_ca_name', 'normalized_ca_name', 'pan_no', 'is_primary', 'page_number',
                    'matched_reference_member_id', 'review_status', 'source_data', 'notes',
                ] as $column) {
                    if (Schema::hasColumn('ocr_parsed_members', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
