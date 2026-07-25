<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales-only contact channels — never overwrite ca_masters.mobile_no / email_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_contacts')) {
            return;
        }

        Schema::create('sales_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ca_id')->index();
            $table->unsignedBigInteger('sales_master_link_id')->nullable()->index();
            $table->unsignedBigInteger('import_batch_id')->nullable()->index();
            $table->unsignedBigInteger('sales_import_row_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('sales_mobile', 30)->nullable();
            $table->string('normalized_sales_mobile', 32)->nullable()->index();
            $table->string('sales_alternate_mobile', 30)->nullable();
            $table->string('sales_email', 255)->nullable();
            $table->string('normalized_sales_email', 255)->nullable()->index();
            $table->string('sales_website', 255)->nullable();
            $table->boolean('is_primary_sales')->default(false);
            $table->timestamps();

            $table->index(['ca_id', 'is_primary_sales'], 'sales_contacts_ca_primary_index');
        });

        if (Schema::hasTable('ca_masters')) {
            Schema::table('sales_contacts', function (Blueprint $table) {
                $table->foreign('ca_id', 'sales_contacts_ca_fk')
                    ->references('ca_id')->on('ca_masters');
            });
        }
        if (Schema::hasTable('sales_master_links')) {
            Schema::table('sales_contacts', function (Blueprint $table) {
                $table->foreign('sales_master_link_id', 'sales_contacts_link_fk')
                    ->references('id')->on('sales_master_links')->nullOnDelete();
            });
        }
        if (Schema::hasTable('master_import_batches')) {
            Schema::table('sales_contacts', function (Blueprint $table) {
                $table->foreign('import_batch_id', 'sales_contacts_batch_fk')
                    ->references('id')->on('master_import_batches')->nullOnDelete();
            });
        }
        if (Schema::hasTable('sales_import_rows')) {
            Schema::table('sales_contacts', function (Blueprint $table) {
                $table->foreign('sales_import_row_id', 'sales_contacts_row_fk')
                    ->references('id')->on('sales_import_rows')->nullOnDelete();
            });
        }
        if (Schema::hasTable('employees')) {
            Schema::table('sales_contacts', function (Blueprint $table) {
                $table->foreign('employee_id', 'sales_contacts_employee_fk')
                    ->references('employee_id')->on('employees')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_contacts');
    }
};
