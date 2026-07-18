<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive staging columns for enterprise OCR accuracy (raw/parsed already in JSON).
 * Stores row geometry hints without wiping existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms')) {
            return;
        }

        Schema::table('ocr_parsed_firms', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_parsed_firms', 'row_number')) {
                $table->unsignedInteger('row_number')->nullable()->after('page_number');
            }
            if (! Schema::hasColumn('ocr_parsed_firms', 'bounding_box')) {
                $table->json('bounding_box')->nullable()->after('field_meta');
            }
            if (! Schema::hasColumn('ocr_parsed_firms', 'validation_errors')) {
                $table->json('validation_errors')->nullable()->after('bounding_box');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms')) {
            return;
        }

        Schema::table('ocr_parsed_firms', function (Blueprint $table) {
            foreach (['validation_errors', 'bounding_box', 'row_number'] as $col) {
                if (Schema::hasColumn('ocr_parsed_firms', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
