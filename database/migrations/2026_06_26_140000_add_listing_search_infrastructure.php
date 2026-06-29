<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('saved_listing_filters')) {
            Schema::create('saved_listing_filters', function (Blueprint $table) {
                $table->id();
                $table->string('listing_key', 80)->index();
                $table->string('name', 120);
                $table->json('filters');
                $table->string('user_id', 80)->nullable()->index();
                $table->boolean('is_preset')->default(false);
                $table->timestamps();
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

            $this->createTrgmIndex('ca_masters', 'firm_name');
            $this->createTrgmIndex('ca_masters', 'ca_name');
            $this->createTrgmIndex('ca_masters', 'mobile_no');
            $this->createTrgmIndex('employees', 'name');
            $this->createTrgmIndex('activity_logs', 'description');
        }

        Schema::table('ca_masters', function (Blueprint $table) {
            if (! $this->indexExists('ca_masters', 'ca_masters_status_created_at_index')) {
                $table->index(['status', 'created_at'], 'ca_masters_status_created_at_index');
            }
            if (! $this->indexExists('ca_masters', 'ca_masters_city_id_status_index')) {
                $table->index(['city_id', 'status'], 'ca_masters_city_id_status_index');
            }
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            if (! $this->indexExists('follow_ups', 'follow_ups_scheduled_date_index')) {
                $table->index('scheduled_date', 'follow_ups_scheduled_date_index');
            }
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            if (! $this->indexExists('activity_logs', 'activity_logs_module_action_created_index')) {
                $table->index(['module_name', 'action', 'created_at'], 'activity_logs_module_action_created_index');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_listing_filters');

        Schema::table('ca_masters', function (Blueprint $table) {
            $table->dropIndex('ca_masters_status_created_at_index');
            $table->dropIndex('ca_masters_city_id_status_index');
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropIndex('follow_ups_scheduled_date_index');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_module_action_created_index');
        });
    }

    private function createTrgmIndex(string $table, string $column): void
    {
        $index = "{$table}_{$column}_trgm_idx";
        DB::statement("CREATE INDEX IF NOT EXISTS {$index} ON {$table} USING gin ({$column} gin_trgm_ops)");
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            $result = DB::selectOne(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index],
            );

            return $result !== null;
        }

        return false;
    }
};
