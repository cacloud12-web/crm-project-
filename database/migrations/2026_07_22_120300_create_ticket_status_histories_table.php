<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_status_histories')) {
            return;
        }

        Schema::create('ticket_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('from_priority', 32)->nullable();
            $table->string('to_priority', 32)->nullable();

            $table->unsignedBigInteger('from_assigned_to_employee_id')->nullable();
            $table->unsignedBigInteger('to_assigned_to_employee_id')->nullable();

            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // crm | ca_cloud_desk | system
            $table->string('change_source', 32)->default('crm');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('from_assigned_to_employee_id')
                ->references('employee_id')
                ->on('employees')
                ->nullOnDelete();
            $table->foreign('to_assigned_to_employee_id')
                ->references('employee_id')
                ->on('employees')
                ->nullOnDelete();

            $table->index(['support_ticket_id', 'created_at'], 'ticket_status_histories_ticket_created_index');
            $table->index('to_status', 'ticket_status_histories_to_status_index');
            $table->index('change_source', 'ticket_status_histories_change_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_status_histories');
    }
};
