<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Support\Database\MigrationIndexHelper;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
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

        Schema::table('activity_logs', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('activity_logs', 'activity_logs_performed_by_index')) {
                $table->index('performed_by', 'activity_logs_performed_by_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (MigrationIndexHelper::exists('activity_logs', 'activity_logs_performed_by_index')) {
                $table->dropIndex('activity_logs_performed_by_index');
            }
            $table->dropColumn(['before_value', 'after_value', 'ip_address']);
        });
    }

};
