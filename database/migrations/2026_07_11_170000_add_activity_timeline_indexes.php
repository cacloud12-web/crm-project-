<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_histories', function (Blueprint $table) {
            if (! $this->hasIndex('follow_up_histories', 'follow_up_histories_followup_created_index')) {
                $table->index(['followup_id', 'created_at'], 'follow_up_histories_followup_created_index');
            }
        });

        Schema::table('call_logs', function (Blueprint $table) {
            if (! $this->hasIndex('call_logs', 'call_logs_ca_called_index')) {
                $table->index(['ca_id', 'called_at'], 'call_logs_ca_called_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_histories', function (Blueprint $table) {
            if ($this->hasIndex('follow_up_histories', 'follow_up_histories_followup_created_index')) {
                $table->dropIndex('follow_up_histories_followup_created_index');
            }
        });

        Schema::table('call_logs', function (Blueprint $table) {
            if ($this->hasIndex('call_logs', 'call_logs_ca_called_index')) {
                $table->dropIndex('call_logs_ca_called_index');
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            $result = $connection->select(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index]
            );

            return $result !== [];
        }

        return false;
    }
};
