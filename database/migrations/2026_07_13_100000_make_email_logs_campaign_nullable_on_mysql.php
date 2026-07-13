<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_logs') || ! Schema::hasColumn('email_logs', 'campaign_id')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE email_logs MODIFY campaign_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_logs') || ! Schema::hasColumn('email_logs', 'campaign_id')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE email_logs MODIFY campaign_id BIGINT UNSIGNED NOT NULL');
    }
};
