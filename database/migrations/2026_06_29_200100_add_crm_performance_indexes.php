<?php

use App\Support\Database\MigrationIndexHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_alternate_mobile_no_index')) {
                $table->index('alternate_mobile_no', 'ca_masters_alternate_mobile_no_index');
            }
            if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_city_id_index')) {
                $table->index('city_id', 'ca_masters_city_id_index');
            }
            if (Schema::hasColumn('ca_masters', 'priority') && ! MigrationIndexHelper::exists('ca_masters', 'ca_masters_priority_index')) {
                $table->index('priority', 'ca_masters_priority_index');
            }
            if (Schema::hasColumn('ca_masters', 'research_status') && ! MigrationIndexHelper::exists('ca_masters', 'ca_masters_research_status_index')) {
                $table->index('research_status', 'ca_masters_research_status_index');
            }
        });

        Schema::table('lead_assignment_engines', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('lead_assignment_engines', 'lead_assignment_engines_employee_id_index')) {
                $table->index('employee_id', 'lead_assignment_engines_employee_id_index');
            }
        });

        foreach (['sms_logs', 'email_logs', 'wa_message_logs'] as $logTable) {
            if (! Schema::hasTable($logTable)) {
                continue;
            }

            Schema::table($logTable, function (Blueprint $table) use ($logTable) {
                $caIndex = "{$logTable}_ca_id_index";
                if (Schema::hasColumn($logTable, 'ca_id') && ! MigrationIndexHelper::exists($logTable, $caIndex)) {
                    $table->index('ca_id', $caIndex);
                }

                $createdIndex = "{$logTable}_created_at_index";
                if (Schema::hasColumn($logTable, 'created_at') && ! MigrationIndexHelper::exists($logTable, $createdIndex)) {
                    $table->index('created_at', $createdIndex);
                }
            });
        }

        if (Schema::hasTable('wa_message_logs') && Schema::hasColumn('wa_message_logs', 'mobile_no') && DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wa_message_logs ALTER COLUMN mobile_no DROP NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            foreach ([
                'ca_masters_alternate_mobile_no_index',
                'ca_masters_city_id_index',
                'ca_masters_priority_index',
                'ca_masters_research_status_index',
            ] as $index) {
                if (MigrationIndexHelper::exists('ca_masters', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        Schema::table('lead_assignment_engines', function (Blueprint $table) {
            if (MigrationIndexHelper::exists('lead_assignment_engines', 'lead_assignment_engines_employee_id_index')) {
                $table->dropIndex('lead_assignment_engines_employee_id_index');
            }
        });

        foreach (['sms_logs', 'email_logs', 'wa_message_logs'] as $logTable) {
            if (! Schema::hasTable($logTable)) {
                continue;
            }

            Schema::table($logTable, function (Blueprint $table) use ($logTable) {
                foreach (["{$logTable}_ca_id_index", "{$logTable}_created_at_index"] as $index) {
                    if (MigrationIndexHelper::exists($logTable, $index)) {
                        $table->dropIndex($index);
                    }
                }
            });
        }
    }
};
