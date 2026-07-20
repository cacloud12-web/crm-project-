<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable partner CAs under a Merchant Centre (ca_masters firm row).
 * One firm → many partners; at most one is_primary per firm.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ca_master_partners')) {
            return;
        }

        Schema::create('ca_master_partners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ca_id');
            $table->string('ca_name');
            $table->string('membership_no', 64)->nullable();
            $table->string('mobile', 32)->nullable();
            $table->string('alternate_mobile', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('designation', 64)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 32)->default('active');
            $table->unsignedInteger('sequence_no')->default(0);
            $table->timestamps();

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
            $table->index(['ca_id', 'is_primary']);
            $table->index('membership_no');
            $table->index('mobile');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ca_master_partners');
    }
};
