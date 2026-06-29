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
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id('followup_id');

            $table->unsignedBigInteger('ca_id');
            $table->unsignedBigInteger('employee_id')->nullable();

            $table->string('followup_type');
            $table->text('remarks')->nullable();
            $table->dateTime('scheduled_date')->nullable();
            $table->dateTime('next_followup_date')->nullable();
            $table->string('status')->default('Pending');

            $table->timestamps();

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->onDelete('cascade');
            $table->foreign('employee_id')->references('employee_id')->on('employees')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
