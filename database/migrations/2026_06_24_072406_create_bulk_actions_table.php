<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bulk_actions', function (Blueprint $table) {
            $table->id('bulk_action_id');
            $table->string('action_type');
            $table->string('file_name')->nullable();
            $table->integer('total_records')->default(0);
            $table->integer('processed_records')->default(0);
            $table->integer('success_records')->default(0);
            $table->integer('failed_records')->default(0);
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->string('status')->default('Pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('initiated_by')
                ->references('employee_id')
                ->on('employees')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_actions');
    }
};
