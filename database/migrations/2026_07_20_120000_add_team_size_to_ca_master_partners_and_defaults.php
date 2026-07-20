<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Team size is manual-only: default 0 for firms and partners; backfill NULL → 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ca_masters') && Schema::hasColumn('ca_masters', 'team_size')) {
            DB::table('ca_masters')->whereNull('team_size')->update(['team_size' => 0]);
        }

        if (Schema::hasTable('ca_master_partners') && ! Schema::hasColumn('ca_master_partners', 'team_size')) {
            Schema::table('ca_master_partners', function (Blueprint $table) {
                $table->unsignedInteger('team_size')->default(0)->after('email');
            });
            DB::table('ca_master_partners')->whereNull('team_size')->update(['team_size' => 0]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ca_master_partners') && Schema::hasColumn('ca_master_partners', 'team_size')) {
            Schema::table('ca_master_partners', function (Blueprint $table) {
                $table->dropColumn('team_size');
            });
        }
    }
};
