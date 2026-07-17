<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee work-type + demo-assignment fields, and follow-up provider link.
 * Safe additive migration — existing employees default to Calling.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees')) {
            Schema::table('employees', function (Blueprint $table) {
                if (! Schema::hasColumn('employees', 'work_type')) {
                    $table->string('work_type', 32)->default('calling')->after('status');
                    $table->index('work_type', 'employees_work_type_index');
                }
                if (! Schema::hasColumn('employees', 'demo_meeting_link')) {
                    $table->string('demo_meeting_link', 500)->nullable()->after('work_type');
                }
                if (! Schema::hasColumn('employees', 'demo_min_team_size')) {
                    $table->unsignedInteger('demo_min_team_size')->nullable()->after('demo_meeting_link');
                }
                if (! Schema::hasColumn('employees', 'demo_max_team_size')) {
                    $table->unsignedInteger('demo_max_team_size')->nullable()->after('demo_min_team_size');
                }
                if (! Schema::hasColumn('employees', 'active_for_demo')) {
                    $table->boolean('active_for_demo')->default(false)->after('demo_max_team_size');
                    $table->index('active_for_demo', 'employees_active_for_demo_index');
                }
            });

            Schema::table('employees', function (Blueprint $table) {
                $indexes = Schema::getIndexes('employees');
                $names = array_column($indexes, 'name');
                if (! in_array('employees_demo_eligibility_index', $names, true)
                    && Schema::hasColumn('employees', 'active_for_demo')
                    && Schema::hasColumn('employees', 'demo_min_team_size')
                    && Schema::hasColumn('employees', 'demo_max_team_size')) {
                    $table->index(
                        ['active_for_demo', 'work_type', 'demo_min_team_size', 'demo_max_team_size'],
                        'employees_demo_eligibility_index',
                    );
                }
            });
        }

        if (Schema::hasTable('follow_ups') && ! Schema::hasColumn('follow_ups', 'demo_provider_employee_id')) {
            Schema::table('follow_ups', function (Blueprint $table) {
                $table->unsignedBigInteger('demo_provider_employee_id')->nullable()->after('demo_provider_name');
                $table->index('demo_provider_employee_id', 'follow_ups_demo_provider_employee_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('follow_ups') && Schema::hasColumn('follow_ups', 'demo_provider_employee_id')) {
            Schema::table('follow_ups', function (Blueprint $table) {
                $table->dropIndex('follow_ups_demo_provider_employee_id_index');
                $table->dropColumn('demo_provider_employee_id');
            });
        }

        if (! Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            $indexes = Schema::getIndexes('employees');
            $names = array_column($indexes, 'name');
            if (in_array('employees_demo_eligibility_index', $names, true)) {
                $table->dropIndex('employees_demo_eligibility_index');
            }
            if (in_array('employees_active_for_demo_index', $names, true)) {
                $table->dropIndex('employees_active_for_demo_index');
            }
            if (in_array('employees_work_type_index', $names, true)) {
                $table->dropIndex('employees_work_type_index');
            }
            foreach (['active_for_demo', 'demo_max_team_size', 'demo_min_team_size', 'demo_meeting_link', 'work_type'] as $column) {
                if (Schema::hasColumn('employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
