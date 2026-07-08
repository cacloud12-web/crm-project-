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

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE email_logs ALTER COLUMN campaign_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_logs') || ! Schema::hasColumn('email_logs', 'campaign_id')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE email_logs ALTER COLUMN campaign_id SET NOT NULL');
        }
    }
};
