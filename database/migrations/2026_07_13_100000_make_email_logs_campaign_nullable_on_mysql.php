<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_logs') || ! Schema::hasColumn('email_logs', 'campaign_id')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE email_logs MODIFY campaign_id BIGINT UNSIGNED NULL');

            return;
        }

        Schema::table('email_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_logs') || ! Schema::hasColumn('email_logs', 'campaign_id')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE email_logs MODIFY campaign_id BIGINT UNSIGNED NOT NULL');

            return;
        }

        Schema::table('email_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable(false)->change();
        });
    }
};
