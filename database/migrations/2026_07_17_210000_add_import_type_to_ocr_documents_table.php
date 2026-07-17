<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separates Master CA bulk load from Sales Team mapping imports.
 * Additive only — never wipes data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_documents')) {
            return;
        }

        Schema::table('ocr_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_documents', 'import_type')) {
                $table->string('import_type', 32)->nullable()->after('provider');
            }
        });

        Schema::table('ocr_documents', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('ocr_documents'))->pluck('name')->all();
            if (Schema::hasColumn('ocr_documents', 'import_type')
                && ! in_array('ocr_documents_import_type_index', $indexes, true)) {
                $table->index('import_type', 'ocr_documents_import_type_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ocr_documents') || ! Schema::hasColumn('ocr_documents', 'import_type')) {
            return;
        }

        Schema::table('ocr_documents', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('ocr_documents'))->pluck('name')->all();
            if (in_array('ocr_documents_import_type_index', $indexes, true)) {
                $table->dropIndex('ocr_documents_import_type_index');
            }
            $table->dropColumn('import_type');
        });
    }
};
