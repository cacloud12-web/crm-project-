<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('follow_up_histories')) {
            Schema::create('follow_up_histories', function (Blueprint $table) {
                $table->id('history_id');
                $table->unsignedBigInteger('followup_id')->nullable();
                $table->unsignedBigInteger('ca_id');
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('event_type');
                $table->string('outcome')->nullable();
                $table->text('remarks')->nullable();
                $table->json('metadata')->nullable();
                $table->string('performed_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('followup_id')->references('followup_id')->on('follow_ups')->nullOnDelete();
                $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
                $table->foreign('employee_id')->references('employee_id')->on('employees')->nullOnDelete();
                $table->index(['ca_id', 'created_at'], 'follow_up_histories_ca_created_index');
            });
        }

        if (! Schema::hasTable('follow_up_reminders')) {
            Schema::create('follow_up_reminders', function (Blueprint $table) {
                $table->id('reminder_id');
                $table->unsignedBigInteger('followup_id');
                $table->unsignedBigInteger('task_id')->nullable();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('reminder_type');
                $table->timestamp('remind_at');
                $table->string('status')->default('Pending');
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->foreign('followup_id')->references('followup_id')->on('follow_ups')->cascadeOnDelete();
                $table->foreign('task_id')->references('task_id')->on('tasks')->nullOnDelete();
                $table->foreign('employee_id')->references('employee_id')->on('employees')->nullOnDelete();
                $table->index(['status', 'remind_at'], 'follow_up_reminders_status_at_index');
            });
        }

        if (! Schema::hasTable('follow_up_reschedule_logs')) {
            Schema::create('follow_up_reschedule_logs', function (Blueprint $table) {
                $table->id('log_id');
                $table->unsignedBigInteger('followup_id');
                $table->unsignedBigInteger('ca_id');
                $table->dateTime('old_scheduled_at')->nullable();
                $table->dateTime('new_scheduled_at')->nullable();
                $table->text('reason')->nullable();
                $table->string('changed_by')->nullable();
                $table->timestamp('changed_at')->useCurrent();

                $table->foreign('followup_id')->references('followup_id')->on('follow_ups')->cascadeOnDelete();
                $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('follow_up_sequence_configs')) {
            Schema::create('follow_up_sequence_configs', function (Blueprint $table) {
                $table->id('config_id');
                $table->string('name')->default('Default Sequence');
                $table->boolean('is_active')->default(true);
                $table->json('sequence_days');
                $table->json('trigger_outcomes')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();
            });

            DB::table('follow_up_sequence_configs')->insert([
                'name' => 'Default Sequence',
                'is_active' => true,
                'sequence_days' => json_encode([1, 3, 7, 15, 30]),
                'trigger_outcomes' => json_encode(['No Answer', 'Busy']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_reschedule_logs');
        Schema::dropIfExists('follow_up_reminders');
        Schema::dropIfExists('follow_up_histories');
        Schema::dropIfExists('follow_up_sequence_configs');
    }
};
