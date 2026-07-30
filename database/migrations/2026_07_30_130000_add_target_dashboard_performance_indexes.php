<?php

use App\Support\Database\MigrationIndexHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for demo/target dashboard aggregates (MySQL + SQLite safe).
 * Only adds indexes that are commonly missing for employee_id + date filters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demo_schedules')) {
            Schema::table('demo_schedules', function (Blueprint $table) {
                if (! MigrationIndexHelper::exists('demo_schedules', 'demo_schedules_employee_created_index')) {
                    $table->index(['employee_id', 'created_at'], 'demo_schedules_employee_created_index');
                }
                if (! MigrationIndexHelper::exists('demo_schedules', 'demo_schedules_employee_status_updated_index')) {
                    $table->index(['employee_id', 'status', 'updated_at'], 'demo_schedules_employee_status_updated_index');
                }
            });
        }

        if (Schema::hasTable('ca_masters') && Schema::hasColumn('ca_masters', 'mobile_no')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                // Portable btree for exact/prefix mobile lookups (normalized_mobile already exists).
                if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_mobile_no_index')) {
                    $table->index('mobile_no', 'ca_masters_mobile_no_index');
                }
            });
        }

        if (Schema::hasTable('call_logs')) {
            Schema::table('call_logs', function (Blueprint $table) {
                if (! MigrationIndexHelper::exists('call_logs', 'call_logs_employee_called_at_index')) {
                    $table->index(['employee_id', 'called_at'], 'call_logs_employee_called_at_index');
                }
            });
        }

        if (Schema::hasTable('email_logs')) {
            Schema::table('email_logs', function (Blueprint $table) {
                if (! MigrationIndexHelper::exists('email_logs', 'email_logs_employee_created_at_index')) {
                    $table->index(['employee_id', 'created_at'], 'email_logs_employee_created_at_index');
                }
            });
        }

        if (Schema::hasTable('sms_logs')) {
            Schema::table('sms_logs', function (Blueprint $table) {
                if (! MigrationIndexHelper::exists('sms_logs', 'sms_logs_employee_created_at_index')) {
                    $table->index(['employee_id', 'created_at'], 'sms_logs_employee_created_at_index');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('demo_schedules')) {
            Schema::table('demo_schedules', function (Blueprint $table) {
                foreach (['demo_schedules_employee_created_index', 'demo_schedules_employee_status_updated_index'] as $name) {
                    if (MigrationIndexHelper::exists('demo_schedules', $name)) {
                        $table->dropIndex($name);
                    }
                }
            });
        }

        if (Schema::hasTable('ca_masters')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                if (MigrationIndexHelper::exists('ca_masters', 'ca_masters_mobile_no_index')) {
                    $table->dropIndex('ca_masters_mobile_no_index');
                }
            });
        }

        if (Schema::hasTable('call_logs')) {
            Schema::table('call_logs', function (Blueprint $table) {
                if (MigrationIndexHelper::exists('call_logs', 'call_logs_employee_called_at_index')) {
                    $table->dropIndex('call_logs_employee_called_at_index');
                }
            });
        }

        if (Schema::hasTable('email_logs')) {
            Schema::table('email_logs', function (Blueprint $table) {
                if (MigrationIndexHelper::exists('email_logs', 'email_logs_employee_created_at_index')) {
                    $table->dropIndex('email_logs_employee_created_at_index');
                }
            });
        }

        if (Schema::hasTable('sms_logs')) {
            Schema::table('sms_logs', function (Blueprint $table) {
                if (MigrationIndexHelper::exists('sms_logs', 'sms_logs_employee_created_at_index')) {
                    $table->dropIndex('sms_logs_employee_created_at_index');
                }
            });
        }
    }
};
