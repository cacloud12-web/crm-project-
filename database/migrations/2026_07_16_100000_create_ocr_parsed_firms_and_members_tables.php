<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM staging tables for structured OCR output (not CA Reference).
 * Table names: ocr_parsed_firms / ocr_parsed_members
 * (equivalent role to ocr_extracted_firms / ocr_extracted_members).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ocr_documents')) {
            Schema::table('ocr_documents', function (Blueprint $table) {
                if (! Schema::hasColumn('ocr_documents', 'parse_status')) {
                    $table->string('parse_status', 32)->nullable()->after('status');
                }
                if (! Schema::hasColumn('ocr_documents', 'parsed_firm_count')) {
                    $table->unsignedInteger('parsed_firm_count')->nullable()->after('parse_status');
                }
                if (! Schema::hasColumn('ocr_documents', 'parsed_at')) {
                    $table->timestamp('parsed_at')->nullable()->after('parsed_firm_count');
                }
            });
        }

        if (! Schema::hasTable('ocr_parsed_firms')) {
            Schema::create('ocr_parsed_firms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ocr_document_id')->constrained('ocr_documents')->cascadeOnDelete();
                $table->unsignedInteger('sequence_no')->default(1);
                $table->string('raw_firm_name')->nullable();
                $table->string('firm_name')->nullable();
                $table->string('normalized_firm_name')->nullable();
                $table->string('firm_type', 60)->nullable();
                $table->string('frn', 60)->nullable();
                $table->string('gst_no', 20)->nullable();
                $table->string('pan_no', 20)->nullable();
                $table->text('address')->nullable();
                $table->string('city', 120)->nullable();
                $table->string('district', 120)->nullable();
                $table->string('state', 120)->nullable();
                $table->string('pincode', 12)->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('email', 190)->nullable();
                $table->string('website', 190)->nullable();
                $table->unsignedInteger('partner_count')->nullable();
                $table->string('review_status', 32)->default('pending');
                $table->decimal('overall_confidence', 5, 4)->nullable();
                $table->unsignedInteger('page_number')->nullable();
                $table->unsignedBigInteger('matched_reference_firm_id')->nullable();
                $table->json('source_data')->nullable();
                $table->text('notes')->nullable();
                $table->json('field_meta')->nullable();
                $table->timestamps();

                $table->index(['ocr_document_id', 'sequence_no']);
                $table->index(['ocr_document_id', 'review_status']);
                $table->index('firm_name');
                $table->index('normalized_firm_name');
                $table->index('gst_no');
                $table->index('frn');
                $table->index('matched_reference_firm_id');
            });
        }

        if (! Schema::hasTable('ocr_parsed_members')) {
            Schema::create('ocr_parsed_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ocr_parsed_firm_id')->constrained('ocr_parsed_firms')->cascadeOnDelete();
                $table->unsignedInteger('sequence_no')->default(1);
                $table->string('raw_ca_name')->nullable();
                $table->string('ca_name')->nullable();
                $table->string('normalized_ca_name')->nullable();
                $table->string('membership_no', 60)->nullable();
                $table->string('mobile', 30)->nullable();
                $table->string('email', 190)->nullable();
                $table->string('pan_no', 20)->nullable();
                $table->string('role', 60)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->decimal('overall_confidence', 5, 4)->nullable();
                $table->unsignedInteger('page_number')->nullable();
                $table->unsignedBigInteger('matched_reference_member_id')->nullable();
                $table->string('review_status', 32)->default('pending');
                $table->json('source_data')->nullable();
                $table->text('notes')->nullable();
                $table->json('field_meta')->nullable();
                $table->timestamps();

                $table->index(['ocr_parsed_firm_id', 'sequence_no']);
                $table->index('membership_no');
                $table->index('ca_name');
                $table->index('normalized_ca_name');
                $table->index('matched_reference_member_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_parsed_members');
        Schema::dropIfExists('ocr_parsed_firms');

        if (Schema::hasTable('ocr_documents')) {
            Schema::table('ocr_documents', function (Blueprint $table) {
                foreach (['parse_status', 'parsed_firm_count', 'parsed_at'] as $column) {
                    if (Schema::hasColumn('ocr_documents', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
