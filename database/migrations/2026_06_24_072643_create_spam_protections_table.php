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
        Schema::create('spam_protections', function (Blueprint $table) {
            $table->id('spam_log_id');
            $table->unsignedBigInteger('email_id')->nullable();
            $table->integer('risk_score')->default(0);
            $table->text('reason_flagged')->nullable();
            $table->string('status')->default('Safe');
            $table->timestamp('flagged_at')->nullable();
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
        Schema::dropIfExists('spam_protections');
    }
};
