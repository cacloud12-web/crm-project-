<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_histories', function (Blueprint $table) {
            if (! Schema::hasColumn('assignment_histories', 'assignment_mode')) {
                $table->string('assignment_mode', 64)->nullable()->after('reason');
            }
            if (! Schema::hasColumn('assignment_histories', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('assigned_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assignment_histories', function (Blueprint $table) {
            if (Schema::hasColumn('assignment_histories', 'assignment_mode')) {
                $table->dropColumn('assignment_mode');
            }
            if (Schema::hasColumn('assignment_histories', 'ip_address')) {
                $table->dropColumn('ip_address');
            }
        });
    }
};
