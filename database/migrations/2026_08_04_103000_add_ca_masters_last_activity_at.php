<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ca_masters')) {
            return;
        }

        if (! Schema::hasColumn('ca_masters', 'last_activity_at')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                $table->timestamp('last_activity_at')->nullable()->after('updated_at');
            });
        }

        // Fast initial backfill from updated_at (good enough for sort; writers keep it fresh).
        DB::table('ca_masters')
            ->whereNull('last_activity_at')
            ->update(['last_activity_at' => DB::raw('updated_at')]);

        Schema::table('ca_masters', function (Blueprint $table) {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexes = collect(method_exists($sm, 'getIndexListing')
                ? []
                : []);
            // Create index if missing (ignore duplicate-name errors on re-run).
            try {
                $table->index(['last_activity_at', 'deleted_at'], 'ca_masters_last_activity_deleted_index');
            } catch (\Throwable) {
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ca_masters') || ! Schema::hasColumn('ca_masters', 'last_activity_at')) {
            return;
        }

        Schema::table('ca_masters', function (Blueprint $table) {
            try {
                $table->dropIndex('ca_masters_last_activity_deleted_index');
            } catch (\Throwable) {
            }
            $table->dropColumn('last_activity_at');
        });
    }
};
