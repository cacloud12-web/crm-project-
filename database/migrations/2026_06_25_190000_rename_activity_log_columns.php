<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE activity_logs RENAME COLUMN module TO module_name');
            DB::statement('ALTER TABLE activity_logs RENAME COLUMN user_name TO performed_by');
            DB::statement('ALTER TABLE activity_logs RENAME COLUMN detail TO description');
        } elseif (DB::getDriverName() === 'mysql' && Schema::hasColumn('activity_logs', 'module')) {
            DB::statement('ALTER TABLE activity_logs CHANGE `module` `module_name` VARCHAR(255) NOT NULL');
            DB::statement("ALTER TABLE activity_logs CHANGE `user_name` `performed_by` VARCHAR(255) NOT NULL DEFAULT 'System'");
            DB::statement('ALTER TABLE activity_logs CHANGE `detail` `description` TEXT NULL');
        }

        if (! Schema::hasColumn('activity_logs', 'module_name')) {
            return;
        }

        DB::table('activity_logs')->where('action', 'Insert')->where('module_name', 'CA_MASTER')->update(['action' => 'Add Lead']);
        DB::table('activity_logs')->where('action', 'Update')->where('module_name', 'CA_MASTER')->update(['action' => 'Update Lead']);
        DB::table('activity_logs')->where('action', 'Delete')->where('module_name', 'CA_MASTER')->update(['action' => 'Delete Lead']);
        DB::table('activity_logs')->where('action', 'Insert')->where('module_name', 'EMPLOYEE_MASTER')->update(['action' => 'Add Employee']);
        DB::table('activity_logs')->where('action', 'Update')->where('module_name', 'EMPLOYEE_MASTER')->update(['action' => 'Update Employee']);
        DB::table('activity_logs')->where('action', 'Delete')->where('module_name', 'EMPLOYEE_MASTER')->update(['action' => 'Delete Employee']);
        DB::table('activity_logs')->whereIn('action', ['Assign', 'Reassign'])->where('module_name', 'LEAD_ASSIGNMENT_ENGINE')->update(['action' => 'Lead Assignment']);
        DB::table('activity_logs')->where('action', 'Import')->where('module_name', 'BULK_ACTIONS')->update(['action' => 'Bulk Import']);
        DB::table('activity_logs')->where('action', 'Insert')->where('module_name', 'FOLLOW_UP_MANAGEMENT')->update(['action' => 'Follow-up Create']);
        DB::table('activity_logs')->where('action', 'Update')->where('module_name', 'FOLLOW_UP_MANAGEMENT')->update(['action' => 'Follow-up Update']);
        DB::table('activity_logs')->where('action', 'Delete')->where('module_name', 'FOLLOW_UP_MANAGEMENT')->update(['action' => 'Follow-up Delete']);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE activity_logs RENAME COLUMN module_name TO module');
            DB::statement('ALTER TABLE activity_logs RENAME COLUMN performed_by TO user_name');
            DB::statement('ALTER TABLE activity_logs RENAME COLUMN description TO detail');

            return;
        }

        if (DB::getDriverName() === 'mysql' && Schema::hasColumn('activity_logs', 'module_name')) {
            DB::statement('ALTER TABLE activity_logs CHANGE `module_name` `module` VARCHAR(255) NOT NULL');
            DB::statement("ALTER TABLE activity_logs CHANGE `performed_by` `user_name` VARCHAR(255) NOT NULL DEFAULT 'System'");
            DB::statement('ALTER TABLE activity_logs CHANGE `description` `detail` TEXT NULL');
        }
    }
};
