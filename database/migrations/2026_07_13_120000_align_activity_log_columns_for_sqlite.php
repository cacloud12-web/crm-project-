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
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->dropSqliteActivityLogIndexes();

            if (Schema::hasColumn('activity_logs', 'user_name') && ! Schema::hasColumn('activity_logs', 'performed_by')) {
                DB::statement('ALTER TABLE activity_logs RENAME COLUMN user_name TO performed_by');
            }
            if (Schema::hasColumn('activity_logs', 'module') && ! Schema::hasColumn('activity_logs', 'module_name')) {
                DB::statement('ALTER TABLE activity_logs RENAME COLUMN module TO module_name');
            }
            if (Schema::hasColumn('activity_logs', 'detail') && ! Schema::hasColumn('activity_logs', 'description')) {
                DB::statement('ALTER TABLE activity_logs RENAME COLUMN detail TO description');
            }
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('activity_logs', 'performed_by')) {
                $table->string('performed_by')->default('System')->after('id');
            }
            if (! Schema::hasColumn('activity_logs', 'module_name')) {
                $table->string('module_name')->default('SYSTEM')->after('performed_by');
            }
            if (! Schema::hasColumn('activity_logs', 'record_id')) {
                $table->string('record_id')->nullable()->after('module_name');
            }
            if (! Schema::hasColumn('activity_logs', 'action')) {
                $table->string('action')->default('Update')->after('record_id');
            }
            if (! Schema::hasColumn('activity_logs', 'description')) {
                $table->text('description')->nullable()->after('action');
            }
            if (! Schema::hasColumn('activity_logs', 'before_value')) {
                $table->text('before_value')->nullable()->after('description');
            }
            if (! Schema::hasColumn('activity_logs', 'after_value')) {
                $table->text('after_value')->nullable()->after('before_value');
            }
            if (! Schema::hasColumn('activity_logs', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('after_value');
            }
        });

        if (Schema::hasColumn('activity_logs', 'user_name') && Schema::hasColumn('activity_logs', 'performed_by')) {
            DB::statement("UPDATE activity_logs SET performed_by = user_name WHERE performed_by IS NULL OR performed_by = ''");
        }

        if (Schema::hasColumn('activity_logs', 'module') && Schema::hasColumn('activity_logs', 'module_name')) {
            DB::statement("UPDATE activity_logs SET module_name = module WHERE module_name IS NULL OR module_name = ''");
        }

        if (Schema::hasColumn('activity_logs', 'detail') && Schema::hasColumn('activity_logs', 'description')) {
            DB::statement('UPDATE activity_logs SET description = detail WHERE description IS NULL');
        }

        if (Schema::hasColumn('activity_logs', 'module_name')) {
            $this->normalizeLegacyActions();
        }

        if (Schema::hasColumn('activity_logs', 'performed_by')
            && ! MigrationIndexHelper::exists('activity_logs', 'activity_logs_performed_by_index')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index('performed_by', 'activity_logs_performed_by_index');
            });
        }

        if (Schema::hasColumn('activity_logs', 'module_name')
            && ! MigrationIndexHelper::exists('activity_logs', 'activity_logs_module_action_created_index')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index(['module_name', 'action', 'created_at'], 'activity_logs_module_action_created_index');
            });
        }

        if (Schema::hasColumn('activity_logs', 'created_at')
            && ! MigrationIndexHelper::exists('activity_logs', 'activity_logs_created_at_index')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index('created_at', 'activity_logs_created_at_index');
            });
        }
    }

    private function dropSqliteActivityLogIndexes(): void
    {
        foreach ([
            'activity_logs_performed_by_index',
            'activity_logs_module_action_created_index',
            'activity_logs_created_at_index',
        ] as $index) {
            if (MigrationIndexHelper::exists('activity_logs', $index)) {
                DB::statement('DROP INDEX '.$index);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        if (MigrationIndexHelper::exists('activity_logs', 'activity_logs_performed_by_index')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->dropIndex('activity_logs_performed_by_index');
            });
        }

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        if (Schema::hasColumn('activity_logs', 'performed_by') && ! Schema::hasColumn('activity_logs', 'user_name')) {
            DB::statement('ALTER TABLE activity_logs RENAME COLUMN performed_by TO user_name');
        }
        if (Schema::hasColumn('activity_logs', 'module_name') && ! Schema::hasColumn('activity_logs', 'module')) {
            DB::statement('ALTER TABLE activity_logs RENAME COLUMN module_name TO module');
        }
        if (Schema::hasColumn('activity_logs', 'description') && ! Schema::hasColumn('activity_logs', 'detail')) {
            DB::statement('ALTER TABLE activity_logs RENAME COLUMN description TO detail');
        }
    }

    private function normalizeLegacyActions(): void
    {
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
};
