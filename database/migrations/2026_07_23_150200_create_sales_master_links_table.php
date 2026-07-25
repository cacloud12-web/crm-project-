<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales → Master link rows (enrichment only; never writes Master identity).
 * PK refs: ca_masters.ca_id, master_import_batches.id, sales_import_rows.id, employees.employee_id
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_master_links')) {
            return;
        }

        Schema::create('sales_master_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ca_id')->index();
            $table->unsignedBigInteger('import_batch_id')->index();
            $table->unsignedBigInteger('sales_import_row_id');
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('match_tier', 40)->nullable()->index();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('sales_source', 120)->nullable();
            $table->string('csv_filename', 255)->nullable();
            $table->unsignedInteger('csv_row_number')->nullable();
            $table->timestamp('linked_at')->nullable()->index();
            $table->timestamps();

            $table->unique('sales_import_row_id', 'sales_master_links_row_unique');
            $table->index(['ca_id', 'employee_id'], 'sales_master_links_ca_employee_index');
            $table->index(['import_batch_id', 'ca_id'], 'sales_master_links_batch_ca_index');
        });

        // Formal FKs only when referenced tables exist (production-safe).
        if (Schema::hasTable('ca_masters')
            && Schema::hasTable('master_import_batches')
            && Schema::hasTable('sales_import_rows')) {
            Schema::table('sales_master_links', function (Blueprint $table) {
                $table->foreign('ca_id', 'sales_master_links_ca_fk')
                    ->references('ca_id')->on('ca_masters');
                $table->foreign('import_batch_id', 'sales_master_links_batch_fk')
                    ->references('id')->on('master_import_batches');
                $table->foreign('sales_import_row_id', 'sales_master_links_row_fk')
                    ->references('id')->on('sales_import_rows');
            });
        }
        if (Schema::hasTable('employees')) {
            Schema::table('sales_master_links', function (Blueprint $table) {
                $table->foreign('employee_id', 'sales_master_links_employee_fk')
                    ->references('employee_id')->on('employees')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_master_links');
    }
};
