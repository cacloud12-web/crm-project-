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
        Schema::create('ca_masters', function (Blueprint $table) {
            $table->id('ca_id');

            $table->string('ca_name');
            $table->string('firm_name')->nullable();
            $table->string('mobile_no');
            $table->string('email_id')->nullable();

            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->integer('team_size')->nullable();
            $table->string('existing_software')->nullable();
            $table->string('website')->nullable();
            $table->string('gst_no')->nullable();

            $table->integer('rating')->default(1);
            $table->boolean('is_newly_established')->default(false);
            $table->string('status')->default('Active');

            $table->timestamps();

            $table->foreign('city_id')->references('city_id')->on('cities')->nullOnDelete();
            $table->foreign('state_id')->references('state_id')->on('states')->nullOnDelete();
            $table->foreign('source_id')->references('source_id')->on('source_leads')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ca_masters');
    }
};
