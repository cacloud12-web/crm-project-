<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_list_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('serial_number')->unique();
            $table->unsignedBigInteger('ca_id');
            $table->unsignedBigInteger('purchased_customer_id')->nullable()->unique();
            $table->unsignedBigInteger('demo_result_id')->nullable();
            $table->string('sale_month', 20)->nullable();
            $table->unsignedSmallInteger('points')->default(1);
            $table->string('customer_name')->nullable();
            $table->string('firm_name')->nullable();
            $table->string('reference_name')->nullable();
            $table->string('mobile_no', 30)->nullable();
            $table->string('city_name')->nullable();
            $table->string('plan_purchased')->nullable();
            $table->date('purchase_date');
            $table->unsignedSmallInteger('cooling_period_days')->default(0);
            $table->date('expiry_date')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('amount_received', 12, 2)->default(0);
            $table->decimal('balance_amount', 12, 2)->default(0);
            $table->string('invoice_number', 40)->unique();
            $table->string('payment_status', 30)->default('Pending');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
            $table->foreign('purchased_customer_id')->references('id')->on('purchased_customers')->nullOnDelete();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->nullOnDelete();
            $table->foreign('manager_id')->references('employee_id')->on('employees')->nullOnDelete();
            $table->index(['purchase_date', 'payment_status']);
            $table->index('sale_month');
            $table->index('plan_purchased');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_list_entries');
    }
};
