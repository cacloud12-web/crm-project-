<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\Database\MigrationIndexHelper;
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
                if (! MigrationIndexHelper::exists($table, $statusIndex)) {
                    $blueprint->index($statusColumn, $statusIndex);
                }

                $campaignIndex = $table.'_'.$campaignColumn.'_index';
                if (! MigrationIndexHelper::exists($table, $campaignIndex)) {
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
                    if (MigrationIndexHelper::exists($table, $index)) {
                        $blueprint->dropIndex($index);
                    }
                }
            });
        }
    }

};
