<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_confirmations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('followup_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->date('demo_date');
            $table->time('demo_time');
            $table->string('confirmation_status', 32)->default('pending');
            $table->unsignedBigInteger('sms_log_id')->nullable();
            $table->text('customer_reply')->nullable();
            $table->string('confirmation_source', 32)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_sms_sent_at')->nullable();
            $table->boolean('is_reschedule')->default(false);
            $table->unsignedBigInteger('previous_confirmation_id')->nullable();
            $table->timestamps();

            $table->foreign('lead_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
            $table->foreign('followup_id')->references('followup_id')->on('follow_ups')->cascadeOnDelete();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->nullOnDelete();
            $table->foreign('sms_log_id')->references('id')->on('sms_logs')->nullOnDelete();
            $table->foreign('previous_confirmation_id')->references('id')->on('demo_confirmations')->nullOnDelete();

            $table->index(['lead_id', 'confirmation_status']);
            $table->index(['followup_id', 'confirmation_status']);
        });

        Schema::create('demo_reschedule_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('demo_confirmation_id');
            $table->unsignedBigInteger('followup_id');
            $table->unsignedBigInteger('lead_id');
            $table->date('old_demo_date');
            $table->time('old_demo_time');
            $table->date('new_demo_date');
            $table->time('new_demo_time');
            $table->string('changed_by')->nullable();
            $table->unsignedBigInteger('changed_by_employee_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('demo_confirmation_id')->references('id')->on('demo_confirmations')->cascadeOnDelete();
            $table->foreign('followup_id')->references('followup_id')->on('follow_ups')->cascadeOnDelete();
            $table->foreign('lead_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
            $table->foreign('changed_by_employee_id')->references('employee_id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_reschedule_logs');
        Schema::dropIfExists('demo_confirmations');
    }
};
