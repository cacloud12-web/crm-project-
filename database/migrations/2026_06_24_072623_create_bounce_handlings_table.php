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
        Schema::create('bounce_handlings', function (Blueprint $table) {
            $table->id('bounce_id');
            $table->unsignedBigInteger('email_id')->nullable();
            $table->string('bounce_type');
            $table->text('bounce_reason')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('status')->default('Pending');
            $table->timestamps();

            $table->foreign('email_id')
                ->references('id')
                ->on('email_logs')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bounce_handlings');
    }
};
