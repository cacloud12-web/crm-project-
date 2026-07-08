<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_message_logs', function (Blueprint $table) {
            if (Schema::hasColumn('wa_message_logs', 'campaign_id')) {
                $table->unsignedBigInteger('campaign_id')->nullable()->change();
            }
            if (Schema::hasColumn('wa_message_logs', 'ca_id')) {
                $table->unsignedBigInteger('ca_id')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('wa_message_logs', function (Blueprint $table) {
            if (Schema::hasColumn('wa_message_logs', 'campaign_id')) {
                $table->unsignedBigInteger('campaign_id')->nullable(false)->change();
            }
            if (Schema::hasColumn('wa_message_logs', 'ca_id')) {
                $table->unsignedBigInteger('ca_id')->nullable(false)->change();
            }
        });
    }
};
