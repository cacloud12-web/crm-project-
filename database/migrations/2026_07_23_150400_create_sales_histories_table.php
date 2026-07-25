<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only Sales History. One history per sales_import_row.
 * Multiple employees may each have histories on the same ca_id.
 * Never update-in-place to erase prior remarks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_histories')) {
            return;
        }

        Schema::create('sales_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ca_id')->index();
            $table->unsignedBigInteger('import_batch_id')->index();
            $table->unsignedBigInteger('sales_import_row_id');
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('employee_name', 255)->nullable();
            $table->text('remarks')->nullable();
            $table->text('remarks_2')->nullable();
            $table->text('employee_notes')->nullable();
            $table->string('call_status', 120)->nullable();
            $table->string('follow_up', 255)->nullable();
            $table->string('software', 120)->nullable();
            $table->string('sales_source', 120)->nullable();
            $table->string('csv_filename', 255)->nullable();
            $table->unsignedInteger('csv_row_number')->nullable();
            $table->date('call_date')->nullable();
            $table->json('extra_columns')->nullable();
            $table->timestamp('imported_at')->nullable()->index();
            $table->timestamps();

            $table->unique('sales_import_row_id', 'sales_histories_row_unique');
            $table->index(['ca_id', 'imported_at'], 'sales_histories_ca_imported_index');
            $table->index(['employee_id', 'imported_at'], 'sales_histories_employee_imported_index');
        });

        if (Schema::hasTable('ca_masters')) {
            Schema::table('sales_histories', function (Blueprint $table) {
                $table->foreign('ca_id', 'sales_histories_ca_fk')
                    ->references('ca_id')->on('ca_masters');
            });
        }
        if (Schema::hasTable('master_import_batches')) {
            Schema::table('sales_histories', function (Blueprint $table) {
                $table->foreign('import_batch_id', 'sales_histories_batch_fk')
                    ->references('id')->on('master_import_batches');
            });
        }
        if (Schema::hasTable('sales_import_rows')) {
            Schema::table('sales_histories', function (Blueprint $table) {
                $table->foreign('sales_import_row_id', 'sales_histories_row_fk')
                    ->references('id')->on('sales_import_rows');
            });
        }
        if (Schema::hasTable('employees')) {
            Schema::table('sales_histories', function (Blueprint $table) {
                $table->foreign('employee_id', 'sales_histories_employee_fk')
                    ->references('employee_id')->on('employees')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_histories');
    }
};
