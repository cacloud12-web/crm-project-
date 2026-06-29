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
        Schema::create('lead_actions', function (Blueprint $table) {
            $table->id('action_id');

            $table->unsignedBigInteger('ca_id');
            $table->unsignedBigInteger('employee_id')->nullable();

            $table->string('action_type');
            $table->dateTime('action_at')->nullable();
            $table->text('remarks')->nullable();

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
        Schema::dropIfExists('lead_actions');
    }
};
