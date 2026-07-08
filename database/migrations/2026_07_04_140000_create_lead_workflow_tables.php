<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('call_logs')) {
            Schema::create('call_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ca_id')->index();
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->unsignedBigInteger('followup_id')->nullable()->index();
                $table->timestamp('called_at');
                $table->string('call_status', 40);
                $table->text('call_note')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
                $table->foreign('employee_id')->references('employee_id')->on('employees')->nullOnDelete();
                $table->foreign('followup_id')->references('followup_id')->on('follow_ups')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('demo_schedules')) {
            Schema::create('demo_schedules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ca_id')->index();
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->unsignedBigInteger('followup_id')->nullable()->index();
                $table->unsignedBigInteger('call_log_id')->nullable();
                $table->dateTime('demo_at');
                $table->string('meeting_link', 500);
                $table->string('status', 40)->default('scheduled')->index();
                $table->string('customer_name')->nullable();
                $table->string('firm_name')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
                $table->foreign('employee_id')->references('employee_id')->on('employees')->nullOnDelete();
                $table->foreign('followup_id')->references('followup_id')->on('follow_ups')->nullOnDelete();
                $table->foreign('call_log_id')->references('id')->on('call_logs')->nullOnDelete();
                $table->index(['demo_at', 'status']);
            });
        }

        if (! Schema::hasTable('demo_reminders')) {
            Schema::create('demo_reminders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('demo_schedule_id')->index();
                $table->string('reminder_type', 40);
                $table->string('channel', 20)->default('notification');
                $table->timestamp('remind_at')->index();
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('sent_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->foreign('demo_schedule_id')->references('id')->on('demo_schedules')->cascadeOnDelete();
                $table->unique(['demo_schedule_id', 'reminder_type'], 'demo_reminders_schedule_type_unique');
            });
        }

        if (! Schema::hasTable('demo_results')) {
            Schema::create('demo_results', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('demo_schedule_id')->index();
                $table->unsignedBigInteger('ca_id')->index();
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->string('result', 40)->index();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('next_followup_id')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->foreign('demo_schedule_id')->references('id')->on('demo_schedules')->cascadeOnDelete();
                $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
                $table->foreign('employee_id')->references('employee_id')->on('employees')->nullOnDelete();
                $table->foreign('next_followup_id')->references('followup_id')->on('follow_ups')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('purchased_customers')) {
            Schema::create('purchased_customers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ca_id')->index();
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->unsignedBigInteger('assigned_by_employee_id')->nullable();
                $table->unsignedBigInteger('demo_schedule_id')->nullable();
                $table->unsignedBigInteger('demo_result_id')->nullable();
                $table->string('customer_name')->nullable();
                $table->string('firm_name')->nullable();
                $table->string('mobile_no', 30)->nullable();
                $table->string('email_id')->nullable();
                $table->date('purchase_date');
                $table->string('software_name')->nullable();
                $table->string('reference_employee_name')->nullable();
                $table->string('status', 40)->default('Purchased');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
                $table->foreign('employee_id')->references('employee_id')->on('employees')->nullOnDelete();
                $table->foreign('assigned_by_employee_id')->references('employee_id')->on('employees')->nullOnDelete();
                $table->foreign('demo_schedule_id')->references('id')->on('demo_schedules')->nullOnDelete();
                $table->foreign('demo_result_id')->references('id')->on('demo_results')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('workflow_communication_logs')) {
            Schema::create('workflow_communication_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ca_id')->nullable()->index();
                $table->unsignedBigInteger('demo_schedule_id')->nullable()->index();
                $table->unsignedBigInteger('demo_reminder_id')->nullable()->index();
                $table->string('channel', 20);
                $table->string('recipient', 255)->nullable();
                $table->string('template_key', 80)->nullable();
                $table->string('status', 20)->default('queued');
                $table->text('message')->nullable();
                $table->text('error_message')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('ca_masters')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                if (! Schema::hasColumn('ca_masters', 'workflow_stage')) {
                    $table->string('workflow_stage', 40)->nullable()->after('status')->index();
                }
                if (! Schema::hasColumn('ca_masters', 'call_status')) {
                    $table->string('call_status', 40)->nullable()->after('workflow_stage');
                }
                if (! Schema::hasColumn('ca_masters', 'demo_status')) {
                    $table->string('demo_status', 40)->nullable()->after('call_status');
                }
                if (! Schema::hasColumn('ca_masters', 'software_purchased')) {
                    $table->boolean('software_purchased')->default(false)->after('demo_status');
                }
                if (! Schema::hasColumn('ca_masters', 'purchase_date')) {
                    $table->date('purchase_date')->nullable()->after('software_purchased');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ca_masters')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                foreach (['purchase_date', 'software_purchased', 'demo_status', 'call_status', 'workflow_stage'] as $column) {
                    if (Schema::hasColumn('ca_masters', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('workflow_communication_logs');
        Schema::dropIfExists('purchased_customers');
        Schema::dropIfExists('demo_results');
        Schema::dropIfExists('demo_reminders');
        Schema::dropIfExists('demo_schedules');
        Schema::dropIfExists('call_logs');
    }
};
