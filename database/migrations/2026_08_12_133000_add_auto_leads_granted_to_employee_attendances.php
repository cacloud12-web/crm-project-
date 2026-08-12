<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_attendances')) {
            return;
        }

        Schema::table('employee_attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_attendances', 'auto_leads_granted')) {
                $table->unsignedSmallInteger('auto_leads_granted')->default(0)->after('remarks');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_attendances')) {
            return;
        }

        Schema::table('employee_attendances', function (Blueprint $table) {
            if (Schema::hasColumn('employee_attendances', 'auto_leads_granted')) {
                $table->dropColumn('auto_leads_granted');
            }
        });
    }
};
