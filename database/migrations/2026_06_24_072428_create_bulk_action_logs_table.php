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
        Schema::create('bulk_action_logs', function (Blueprint $table) {
            $table->id('log_id');
            $table->unsignedBigInteger('bulk_action_id');
            $table->integer('row_number')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('bulk_action_id')
                ->references('bulk_action_id')
                ->on('bulk_actions')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_action_logs');
    }
};
