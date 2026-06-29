<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasks')) {
            return;
        }

        Schema::create('tasks', function (Blueprint $table) {
            $table->id('task_id');
            $table->unsignedBigInteger('followup_id')->nullable();
            $table->unsignedBigInteger('ca_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('task_type')->default('Follow-up Call');
            $table->date('due_date');
            $table->time('due_time')->nullable();
            $table->string('priority')->default('Normal');
            $table->string('status')->default('Pending');
            $table->string('task_source')->default('Auto Generated');
            $table->text('remarks')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('followup_id')->references('followup_id')->on('follow_ups')->nullOnDelete();
            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->nullOnDelete();
            $table->index(['employee_id', 'status', 'due_date'], 'tasks_employee_status_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
