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
        Schema::create('throttle_logs', function (Blueprint $table) {
            $table->id('throttle_id');
            $table->string('ip_address');
            $table->string('endpoint');
            $table->text('blocked_until')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('throttle_logs');
    }
};
