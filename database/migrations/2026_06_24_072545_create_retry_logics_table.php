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
        Schema::create('retry_logics', function (Blueprint $table) {
            $table->id('retry_id');
            $table->string('request_id');
            $table->integer('attempt_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('status')->default('Pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retry_logics');
    }
};
