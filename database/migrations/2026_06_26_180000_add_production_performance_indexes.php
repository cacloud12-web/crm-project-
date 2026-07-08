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
        Schema::table('ca_masters', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_status_index')) {
                $table->index('status', 'ca_masters_status_index');
            }
            if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_state_id_index')) {
                $table->index('state_id', 'ca_masters_state_id_index');
            }
            if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_source_id_index')) {
                $table->index('source_id', 'ca_masters_source_id_index');
            }
            if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_email_id_index')) {
                $table->index('email_id', 'ca_masters_email_id_index');
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('employees', 'employees_status_index')) {
                $table->index('status', 'employees_status_index');
            }
        });

        Schema::table('lead_assignment_engines', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('lead_assignment_engines', 'lead_assignment_engines_ca_id_index')) {
                $table->index('ca_id', 'lead_assignment_engines_ca_id_index');
            }
            if (! MigrationIndexHelper::exists('lead_assignment_engines', 'lead_assignment_engines_employee_id_index')) {
                $table->index('employee_id', 'lead_assignment_engines_employee_id_index');
            }
            if (! MigrationIndexHelper::exists('lead_assignment_engines', 'lead_assignment_engines_status_index')) {
                $table->index('status', 'lead_assignment_engines_status_index');
            }
            if (! MigrationIndexHelper::exists('lead_assignment_engines', 'lead_assignment_engines_employee_status_index')) {
                $table->index(['employee_id', 'status'], 'lead_assignment_engines_employee_status_index');
            }
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('follow_ups', 'follow_ups_ca_id_index')) {
                $table->index('ca_id', 'follow_ups_ca_id_index');
            }
            if (! MigrationIndexHelper::exists('follow_ups', 'follow_ups_employee_id_index')) {
                $table->index('employee_id', 'follow_ups_employee_id_index');
            }
            if (! MigrationIndexHelper::exists('follow_ups', 'follow_ups_status_index')) {
                $table->index('status', 'follow_ups_status_index');
            }
            if (! MigrationIndexHelper::exists('follow_ups', 'follow_ups_next_followup_date_index')) {
                $table->index('next_followup_date', 'follow_ups_next_followup_date_index');
            }
            if (! MigrationIndexHelper::exists('follow_ups', 'follow_ups_employee_scheduled_index')) {
                $table->index(['employee_id', 'scheduled_date'], 'follow_ups_employee_scheduled_index');
            }
        });

        Schema::table('bulk_actions', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('bulk_actions', 'bulk_actions_created_at_index')) {
                $table->index('created_at', 'bulk_actions_created_at_index');
            }
        });

        foreach (['whatsapp_campaigns', 'email_campaigns', 'sms_campaigns'] as $campaignTable) {
            if (! Schema::hasTable($campaignTable)) {
                continue;
            }

            Schema::table($campaignTable, function (Blueprint $table) use ($campaignTable) {
                $index = $campaignTable.'_created_at_index';
                if (! MigrationIndexHelper::exists($campaignTable, $index)) {
                    $table->index('created_at', $index);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            $table->dropIndex('ca_masters_status_index');
            $table->dropIndex('ca_masters_state_id_index');
            $table->dropIndex('ca_masters_source_id_index');
            $table->dropIndex('ca_masters_email_id_index');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex('employees_status_index');
        });

        Schema::table('lead_assignment_engines', function (Blueprint $table) {
            $table->dropIndex('lead_assignment_engines_ca_id_index');
            $table->dropIndex('lead_assignment_engines_employee_id_index');
            $table->dropIndex('lead_assignment_engines_status_index');
            $table->dropIndex('lead_assignment_engines_employee_status_index');
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropIndex('follow_ups_ca_id_index');
            $table->dropIndex('follow_ups_employee_id_index');
            $table->dropIndex('follow_ups_status_index');
            $table->dropIndex('follow_ups_next_followup_date_index');
            $table->dropIndex('follow_ups_employee_scheduled_index');
        });

        Schema::table('bulk_actions', function (Blueprint $table) {
            $table->dropIndex('bulk_actions_created_at_index');
        });

        foreach (['whatsapp_campaigns', 'email_campaigns', 'sms_campaigns'] as $campaignTable) {
            if (! Schema::hasTable($campaignTable)) {
                continue;
            }

            Schema::table($campaignTable, function (Blueprint $table) use ($campaignTable) {
                $table->dropIndex($campaignTable.'_created_at_index');
            });
        }
    }

};
