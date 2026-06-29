<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'wa_message_logs' => ['message_status', 'campaign_id'],
            'email_logs' => ['email_status', 'campaign_id'],
            'sms_logs' => ['sms_status', 'campaign_id'],
        ];

        foreach ($tables as $table => [$statusColumn, $campaignColumn]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $statusColumn, $campaignColumn) {
                $statusIndex = $table.'_'.$statusColumn.'_index';
                if (! $this->indexExists($table, $statusIndex)) {
                    $blueprint->index($statusColumn, $statusIndex);
                }

                $campaignIndex = $table.'_'.$campaignColumn.'_index';
                if (! $this->indexExists($table, $campaignIndex)) {
                    $blueprint->index($campaignColumn, $campaignIndex);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['wa_message_logs', 'email_logs', 'sms_logs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach (['message_status', 'email_status', 'sms_status', 'campaign_id'] as $column) {
                    $index = $table.'_'.$column.'_index';
                    if ($this->indexExists($table, $index)) {
                        $blueprint->dropIndex($index);
                    }
                }
            });
        }
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
