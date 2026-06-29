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
        Schema::create('lead_assignment_engines', function (Blueprint $table) {
            $table->id('assignment_id');

            $table->unsignedBigInteger('ca_id');
            $table->unsignedBigInteger('employee_id');

            $table->date('assigned_date')->nullable();
            $table->string('assignment_type')->default('Manual');
            $table->text('rotation_logic_used')->nullable();
            $table->integer('priority_score')->default(1);
            $table->integer('target_leads')->default(0);
            $table->integer('achieved_leads')->default(0);
            $table->string('status')->default('Active');

            $table->timestamps();

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->onDelete('cascade');
            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_assignment_engines');
    }
};
