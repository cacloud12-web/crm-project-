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
        $this->addIndexIfMissing('ca_masters', 'ca_masters_status_index', function (Blueprint $table) {
            $table->index('status', 'ca_masters_status_index');
        });
        $this->addIndexIfMissing('ca_masters', 'ca_masters_created_at_index', function (Blueprint $table) {
            $table->index('created_at', 'ca_masters_created_at_index');
        });
        $this->addIndexIfMissing('ca_masters', 'ca_masters_status_created_index', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'ca_masters_status_created_index');
        });
        $this->addIndexIfMissing('ca_masters', 'ca_masters_source_id_index', function (Blueprint $table) {
            $table->index('source_id', 'ca_masters_source_id_index');
        });
        $this->addIndexIfMissing('ca_masters', 'ca_masters_city_id_index', function (Blueprint $table) {
            $table->index('city_id', 'ca_masters_city_id_index');
        });
        $this->addIndexIfMissing('ca_masters', 'ca_masters_newly_established_index', function (Blueprint $table) {
            $table->index('is_newly_established', 'ca_masters_newly_established_index');
        });

        $this->addIndexIfMissing('lead_assignment_engines', 'lead_assignments_status_ca_index', function (Blueprint $table) {
            $table->index(['status', 'ca_id'], 'lead_assignments_status_ca_index');
        });
        $this->addIndexIfMissing('lead_assignment_engines', 'lead_assignments_status_employee_index', function (Blueprint $table) {
            $table->index(['status', 'employee_id'], 'lead_assignments_status_employee_index');
        });

        $this->addIndexIfMissing('follow_ups', 'follow_ups_status_scheduled_index', function (Blueprint $table) {
            $table->index(['status', 'scheduled_date'], 'follow_ups_status_scheduled_index');
        });
        $this->addIndexIfMissing('follow_ups', 'follow_ups_employee_status_index', function (Blueprint $table) {
            $table->index(['employee_id', 'status'], 'follow_ups_employee_status_index');
        });
        $this->addIndexIfMissing('follow_ups', 'follow_ups_type_index', function (Blueprint $table) {
            $table->index('followup_type', 'follow_ups_type_index');
        });

        if (Schema::hasTable('sms_logs')) {
            $this->addIndexIfMissing('sms_logs', 'sms_logs_status_index', function (Blueprint $table) {
                $table->index('sms_status', 'sms_logs_status_index');
            });
        }

        if (Schema::hasTable('email_logs')) {
            $this->addIndexIfMissing('email_logs', 'email_logs_status_index', function (Blueprint $table) {
                $table->index('email_status', 'email_logs_status_index');
            });
        }

        if (Schema::hasTable('wa_message_logs')) {
            $this->addIndexIfMissing('wa_message_logs', 'wa_message_logs_status_index', function (Blueprint $table) {
                $table->index('message_status', 'wa_message_logs_status_index');
            });
        }

        if (Schema::hasTable('consent_trackings')) {
            $this->addIndexIfMissing('consent_trackings', 'consent_trackings_status_index', function (Blueprint $table) {
                $table->index('consent_status', 'consent_trackings_status_index');
            });
        }

        if (Schema::hasTable('dnd_management')) {
            $this->addIndexIfMissing('dnd_management', 'dnd_management_ca_id_index', function (Blueprint $table) {
                $table->index('ca_id', 'dnd_management_ca_id_index');
            });
        }

        if (Schema::hasTable('activity_logs')) {
            $this->addIndexIfMissing('activity_logs', 'activity_logs_created_at_index', function (Blueprint $table) {
                $table->index('created_at', 'activity_logs_created_at_index');
            });
        }
    }

    public function down(): void
    {
        $this->dropIndexIfExists('ca_masters', 'ca_masters_status_index');
        $this->dropIndexIfExists('ca_masters', 'ca_masters_created_at_index');
        $this->dropIndexIfExists('ca_masters', 'ca_masters_status_created_index');
        $this->dropIndexIfExists('ca_masters', 'ca_masters_source_id_index');
        $this->dropIndexIfExists('ca_masters', 'ca_masters_city_id_index');
        $this->dropIndexIfExists('ca_masters', 'ca_masters_newly_established_index');
        $this->dropIndexIfExists('lead_assignment_engines', 'lead_assignments_status_ca_index');
        $this->dropIndexIfExists('lead_assignment_engines', 'lead_assignments_status_employee_index');
        $this->dropIndexIfExists('follow_ups', 'follow_ups_status_scheduled_index');
        $this->dropIndexIfExists('follow_ups', 'follow_ups_employee_status_index');
        $this->dropIndexIfExists('follow_ups', 'follow_ups_type_index');
        $this->dropIndexIfExists('sms_logs', 'sms_logs_status_index');
        $this->dropIndexIfExists('email_logs', 'email_logs_status_index');
        $this->dropIndexIfExists('wa_message_logs', 'wa_message_logs_status_index');
        $this->dropIndexIfExists('consent_trackings', 'consent_trackings_status_index');
        $this->dropIndexIfExists('dnd_management', 'dnd_management_ca_id_index');
        $this->dropIndexIfExists('activity_logs', 'activity_logs_created_at_index');
    }

    private function addIndexIfMissing(string $table, string $indexName, callable $callback): void
    {
        if (MigrationIndexHelper::exists($table, $indexName)) {
            return;
        }

        Schema::table($table, $callback);
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! MigrationIndexHelper::exists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropIndex($indexName);
        });
    }
};
