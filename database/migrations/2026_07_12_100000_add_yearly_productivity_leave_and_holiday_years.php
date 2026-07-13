<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_holiday_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_holiday_id')->constrained('company_holidays')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->date('holiday_date');
            $table->timestamps();

            $table->unique(['company_holiday_id', 'year']);
            $table->unique(['year', 'holiday_date']);
        });

        Schema::create('employee_leaves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('leave_date');
            $table->unsignedSmallInteger('target_year');
            $table->string('status', 20)->default('pending');
            $table->string('reason', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('counts_against_balance')->default(true);
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
            $table->unique(['employee_id', 'leave_date']);
            $table->index(['employee_id', 'target_year', 'status']);
        });

        Schema::table('yearly_employee_targets', function (Blueprint $table) {
            $table->unsignedTinyInteger('annual_leave_allowance')->default(12)->after('sms_target');
            $table->boolean('allow_negative_leave_balance')->default(false)->after('annual_leave_allowance');
        });
    }

    public function down(): void
    {
        Schema::table('yearly_employee_targets', function (Blueprint $table) {
            $table->dropColumn(['annual_leave_allowance', 'allow_negative_leave_balance']);
        });

        Schema::dropIfExists('employee_leaves');
        Schema::dropIfExists('company_holiday_years');
    }
};
