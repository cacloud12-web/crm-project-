<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_import_batches')) {
            Schema::create('ocr_import_batches', function (Blueprint $table) {
                $table->id();
                $table->string('batch_name')->nullable();
                $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
                $table->unsignedInteger('total_documents')->default(0);
                $table->unsignedInteger('completed_documents')->default(0);
                $table->unsignedInteger('failed_documents')->default(0);
                $table->string('status', 32)->default('pending');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('uploaded_by');
            });
        }

        if (! Schema::hasTable('ocr_documents')) {
            return;
        }

        Schema::table('ocr_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_documents', 'import_batch_id')) {
                $table->unsignedBigInteger('import_batch_id')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('ocr_documents', 'provider')) {
                $table->string('provider', 64)->default('google_document_ai')->after('status');
            }
            if (! Schema::hasColumn('ocr_documents', 'provider_reference')) {
                $table->string('provider_reference')->nullable()->after('provider')->index();
            }
            if (! Schema::hasColumn('ocr_documents', 'corrected_by')) {
                $table->unsignedBigInteger('corrected_by')->nullable()->after('corrected_text');
            }
            if (! Schema::hasColumn('ocr_documents', 'corrected_at')) {
                $table->timestamp('corrected_at')->nullable()->after('corrected_by');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ocr_documents')) {
            Schema::table('ocr_documents', function (Blueprint $table) {
                foreach (['import_batch_id', 'provider', 'provider_reference', 'corrected_by', 'corrected_at'] as $column) {
                    if (Schema::hasColumn('ocr_documents', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('ocr_import_batches');
    }
};
