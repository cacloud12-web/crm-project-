<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_import_rows', function (Blueprint $table) {
            $table->id();

            // Import information
            $table->unsignedBigInteger('import_batch_id')->nullable()->index();
            $table->string('source_file_name')->nullable();
            $table->string('source_sheet_name')->nullable();
            $table->unsignedInteger('source_row_number')->nullable();
            $table->string('employee_name')->nullable();

            // Original employee calling-list data
            $table->date('call_date')->nullable();
            $table->string('ca_name')->nullable();
            $table->string('firm_name')->nullable();
            $table->string('mobile_no', 30)->nullable();
            $table->string('alternate_mobile_no', 30)->nullable();
            $table->string('city_name')->nullable();
            $table->text('remarks_1')->nullable();
            $table->text('remarks_2')->nullable();

            // Normalized values used only for matching
            $table->string('normalized_ca_name')->nullable()->index();
            $table->string('normalized_firm_name')->nullable()->index();
            $table->string('normalized_city')->nullable()->index();

            // Mapping result
            $table->unsignedBigInteger('matched_ca_id')->nullable()->index();
            $table->string('mapping_status', 30)
                ->default('pending')
                ->index();

            $table->string('matched_on')->nullable();
            $table->decimal('match_score', 5, 4)->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamp('mapped_at')->nullable();

            // Original complete CSV row, for safety and audit
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->index(
                ['mapping_status', 'matched_ca_id'],
                'sales_import_status_ca_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_import_rows');
    }
};