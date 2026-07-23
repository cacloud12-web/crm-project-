<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive OCR Needs Verification fields on ca_masters.
 * Do NOT run on production automatically — migrate explicitly after review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('ca_masters', 'verification_status')) {
                $table->string('verification_status', 32)->default('verified')->index();
            }
            if (! Schema::hasColumn('ca_masters', 'data_quality_status')) {
                $table->string('data_quality_status', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('ca_masters', 'data_quality_issue')) {
                $table->string('data_quality_issue', 128)->nullable()->index();
            }
            if (! Schema::hasColumn('ca_masters', 'source_type')) {
                $table->string('source_type', 32)->nullable()->index();
            }
            if (! Schema::hasColumn('ca_masters', 'source_ocr_document_id')) {
                $table->unsignedBigInteger('source_ocr_document_id')->nullable()->index();
            }
            if (! Schema::hasColumn('ca_masters', 'source_ocr_row_id')) {
                $table->unsignedBigInteger('source_ocr_row_id')->nullable()->index();
            }
            if (! Schema::hasColumn('ca_masters', 'ocr_match_status')) {
                $table->string('ocr_match_status', 64)->nullable();
            }
            if (! Schema::hasColumn('ca_masters', 'ocr_review_status')) {
                $table->string('ocr_review_status', 32)->nullable();
            }
            if (! Schema::hasColumn('ca_masters', 'ocr_match_reason')) {
                $table->text('ocr_match_reason')->nullable();
            }
            if (! Schema::hasColumn('ca_masters', 'ocr_validation_errors')) {
                $table->json('ocr_validation_errors')->nullable();
            }
            if (! Schema::hasColumn('ca_masters', 'ocr_city_text')) {
                $table->string('ocr_city_text', 255)->nullable();
            }
        });

        // Allow blank CA name for Needs Verification rows (UI shows "CA Name Missing").
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE ca_masters MODIFY ca_name VARCHAR(255) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE ca_masters ALTER COLUMN ca_name DROP NOT NULL');
        }

        // Unique OCR source row → at most one Master (NULLs allowed for non-OCR rows).
        try {
            Schema::table('ca_masters', function (Blueprint $table) {
                $table->unique('source_ocr_row_id', 'ca_masters_source_ocr_row_unique');
            });
        } catch (\Throwable) {
            // Index may already exist.
        }

        if (Schema::hasColumn('ca_masters', 'verification_status')) {
            DB::table('ca_masters')
                ->where(function ($q) {
                    $q->whereNull('verification_status')->orWhere('verification_status', '');
                })
                ->update(['verification_status' => 'verified']);
        }
    }

    public function down(): void
    {
        try {
            Schema::table('ca_masters', function (Blueprint $table) {
                $table->dropUnique('ca_masters_source_ocr_row_unique');
            });
        } catch (\Throwable) {
        }

        Schema::table('ca_masters', function (Blueprint $table) {
            foreach ([
                'verification_status',
                'data_quality_status',
                'data_quality_issue',
                'source_type',
                'source_ocr_document_id',
                'source_ocr_row_id',
                'ocr_match_status',
                'ocr_review_status',
                'ocr_match_reason',
                'ocr_validation_errors',
                'ocr_city_text',
            ] as $col) {
                if (Schema::hasColumn('ca_masters', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
