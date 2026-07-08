<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Support\Database\MigrationIndexHelper;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_updated_at_index')) {
                $table->index('updated_at', 'ca_masters_updated_at_index');
            }
            if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_verified_lookup_index')) {
                $table->index(['verified_by', 'is_verified'], 'ca_masters_verified_lookup_index');
            }
        });

        Schema::table('lead_assignment_engines', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('lead_assignment_engines', 'lead_assignment_engines_assigned_date_index')) {
                $table->index('assigned_date', 'lead_assignment_engines_assigned_date_index');
            }
            if (! MigrationIndexHelper::exists('lead_assignment_engines', 'lead_assignment_engines_ca_status_index')) {
                $table->index(['ca_id', 'status'], 'lead_assignment_engines_ca_status_index');
            }
        });

        Schema::table('assignment_histories', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('assignment_histories', 'assignment_histories_new_employee_index')) {
                $table->index('new_employee_id', 'assignment_histories_new_employee_index');
            }
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('follow_ups', 'follow_ups_updated_at_index')) {
                $table->index('updated_at', 'follow_ups_updated_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            $table->dropIndex('ca_masters_updated_at_index');
            $table->dropIndex('ca_masters_verified_lookup_index');
        });

        Schema::table('lead_assignment_engines', function (Blueprint $table) {
            $table->dropIndex('lead_assignment_engines_assigned_date_index');
            $table->dropIndex('lead_assignment_engines_ca_status_index');
        });

        Schema::table('assignment_histories', function (Blueprint $table) {
            $table->dropIndex('assignment_histories_new_employee_index');
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropIndex('follow_ups_updated_at_index');
        });
    }

};
