<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['month', 'day', 'name']);
        });

        Schema::create('yearly_employee_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedSmallInteger('target_year');
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->unsignedInteger('lead_target')->default(0);
            $table->unsignedInteger('call_target')->default(0);
            $table->unsignedInteger('demo_target')->default(0);
            $table->unsignedInteger('followup_target')->default(0);
            $table->unsignedInteger('email_target')->default(0);
            $table->unsignedInteger('sms_target')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
            $table->foreign('manager_id')->references('employee_id')->on('employees')->nullOnDelete();
            $table->unique(['employee_id', 'target_year']);
            $table->index('target_year');
        });

        Schema::create('employee_calendar_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('yearly_employee_target_id')->nullable();
            $table->date('calendar_date');
            $table->string('day_type', 20)->default('working');
            $table->string('holiday_name', 120)->nullable();
            $table->unsignedInteger('lead_target')->default(0);
            $table->unsignedInteger('call_target')->default(0);
            $table->unsignedInteger('demo_target')->default(0);
            $table->unsignedInteger('followup_target')->default(0);
            $table->unsignedInteger('email_target')->default(0);
            $table->unsignedInteger('sms_target')->default(0);
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
            $table->foreign('yearly_employee_target_id')->references('id')->on('yearly_employee_targets')->cascadeOnDelete();
            $table->unique(['employee_id', 'calendar_date']);
            $table->index(['employee_id', 'day_type']);
            $table->index('calendar_date');
        });

        Schema::create('yearly_employee_target_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('yearly_employee_target_id')->nullable();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedSmallInteger('target_year');
            $table->string('action', 64);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yearly_employee_target_audits');
        Schema::dropIfExists('employee_calendar_days');
        Schema::dropIfExists('yearly_employee_targets');
        Schema::dropIfExists('company_holidays');
    }
};
