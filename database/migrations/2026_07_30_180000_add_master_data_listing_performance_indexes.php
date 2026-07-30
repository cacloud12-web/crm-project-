<?php

use App\Support\Database\MigrationIndexHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Data listing: COUNT(*) scans ca_masters without a soft-delete-friendly
 * index; activity summaries need ca_id+time indexes on lead_actions and email.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ca_masters')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_deleted_created_index')) {
                    $table->index(['deleted_at', 'created_at'], 'ca_masters_deleted_created_index');
                }
            });
        }

        if (Schema::hasTable('lead_actions')) {
            Schema::table('lead_actions', function (Blueprint $table) {
                if (! MigrationIndexHelper::exists('lead_actions', 'lead_actions_ca_action_at_index')) {
                    $table->index(['ca_id', 'action_at'], 'lead_actions_ca_action_at_index');
                }
            });
        }

        if (Schema::hasTable('email_logs') && Schema::hasColumn('email_logs', 'sent_at')) {
            Schema::table('email_logs', function (Blueprint $table) {
                if (! MigrationIndexHelper::exists('email_logs', 'email_logs_ca_sent_at_index')) {
                    $table->index(['ca_id', 'sent_at'], 'email_logs_ca_sent_at_index');
                }
            });
        }

        if (Schema::hasTable('sms_logs') && Schema::hasColumn('sms_logs', 'sent_at')) {
            Schema::table('sms_logs', function (Blueprint $table) {
                if (! MigrationIndexHelper::exists('sms_logs', 'sms_logs_ca_sent_at_index')) {
                    $table->index(['ca_id', 'sent_at'], 'sms_logs_ca_sent_at_index');
                }
            });
        }

        if (Schema::hasTable('wa_message_logs') && Schema::hasColumn('wa_message_logs', 'sent_at')) {
            Schema::table('wa_message_logs', function (Blueprint $table) {
                if (! MigrationIndexHelper::exists('wa_message_logs', 'wa_message_logs_ca_sent_at_index')) {
                    $table->index(['ca_id', 'sent_at'], 'wa_message_logs_ca_sent_at_index');
                }
            });
        }
    }

    public function down(): void
    {
        $drops = [
            'ca_masters' => ['ca_masters_deleted_created_index'],
            'lead_actions' => ['lead_actions_ca_action_at_index'],
            'email_logs' => ['email_logs_ca_sent_at_index'],
            'sms_logs' => ['sms_logs_ca_sent_at_index'],
            'wa_message_logs' => ['wa_message_logs_ca_sent_at_index'],
        ];

        foreach ($drops as $tableName => $indexes) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $indexes) {
                foreach ($indexes as $index) {
                    if (MigrationIndexHelper::exists($tableName, $index)) {
                        $table->dropIndex($index);
                    }
                }
            });
        }
    }
};
