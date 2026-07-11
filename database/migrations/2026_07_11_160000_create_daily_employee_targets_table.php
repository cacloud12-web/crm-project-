<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_employee_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->date('target_date');
            $table->unsignedInteger('lead_target')->default(0);
            $table->unsignedInteger('call_target')->default(0);
            $table->unsignedInteger('demo_target')->default(0);
            $table->unsignedInteger('followup_target')->default(0);
            $table->unsignedInteger('email_target')->default(0);
            $table->unsignedInteger('sms_target')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
            $table->foreign('manager_id')->references('employee_id')->on('employees')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['employee_id', 'target_date']);
            $table->index('target_date');
            $table->index('manager_id');
            $table->index(['employee_id', 'target_date']);
        });

        Schema::create('daily_employee_target_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('daily_employee_target_id')->nullable();
            $table->unsignedBigInteger('employee_id');
            $table->date('target_date');
            $table->string('action', 64);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('daily_employee_target_id')->references('id')->on('daily_employee_targets')->nullOnDelete();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['employee_id', 'target_date']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_employee_target_audits');
        Schema::dropIfExists('daily_employee_targets');
    }
};
