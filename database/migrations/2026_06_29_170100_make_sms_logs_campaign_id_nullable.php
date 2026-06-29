<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->change();
            $table->foreign('campaign_id')->references('id')->on('sms_campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable(false)->change();
            $table->foreign('campaign_id')->references('id')->on('sms_campaigns')->cascadeOnDelete();
        });
    }
};
