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
        Schema::create('api_rate_limits', function (Blueprint $table) {
            $table->id('rate_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address');
            $table->string('endpoint');
            $table->integer('limit_count');
            $table->integer('time_window');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_rate_limits');
    }
};
