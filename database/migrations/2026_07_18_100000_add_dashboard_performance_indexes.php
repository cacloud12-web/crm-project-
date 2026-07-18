<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('follow_ups')) {
            Schema::table('follow_ups', function (Blueprint $table) {
                if (! $this->indexExists('follow_ups', 'follow_ups_status_scheduled_index')) {
                    $table->index(['status', 'scheduled_date'], 'follow_ups_status_scheduled_index');
                }
                if (! $this->indexExists('follow_ups', 'follow_ups_employee_status_scheduled_index')) {
                    $table->index(['employee_id', 'status', 'scheduled_date'], 'follow_ups_employee_status_scheduled_index');
                }
                if (! $this->indexExists('follow_ups', 'follow_ups_type_status_index')) {
                    $table->index(['followup_type', 'status'], 'follow_ups_type_status_index');
                }
            });
        }

        if (Schema::hasTable('lead_assignment_engines')) {
            Schema::table('lead_assignment_engines', function (Blueprint $table) {
                if (! $this->indexExists('lead_assignment_engines', 'lead_assignments_status_employee_ca_index')) {
                    $table->index(['status', 'employee_id', 'ca_id'], 'lead_assignments_status_employee_ca_index');
                }
            });
        }

        if (Schema::hasTable('duplicate_attempts')) {
            Schema::table('duplicate_attempts', function (Blueprint $table) {
                if (! $this->indexExists('duplicate_attempts', 'duplicate_attempts_created_at_index')) {
                    $table->index(['created_at'], 'duplicate_attempts_created_at_index');
                }
                if (! $this->indexExists('duplicate_attempts', 'duplicate_attempts_type_created_index')) {
                    $table->index(['attempt_type', 'created_at'], 'duplicate_attempts_type_created_index');
                }
                if (! $this->indexExists('duplicate_attempts', 'duplicate_attempts_employee_created_index')) {
                    $table->index(['employee_id', 'created_at'], 'duplicate_attempts_employee_created_index');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('follow_ups')) {
            Schema::table('follow_ups', function (Blueprint $table) {
                if ($this->indexExists('follow_ups', 'follow_ups_status_scheduled_index')) {
                    $table->dropIndex('follow_ups_status_scheduled_index');
                }
                if ($this->indexExists('follow_ups', 'follow_ups_employee_status_scheduled_index')) {
                    $table->dropIndex('follow_ups_employee_status_scheduled_index');
                }
                if ($this->indexExists('follow_ups', 'follow_ups_type_status_index')) {
                    $table->dropIndex('follow_ups_type_status_index');
                }
            });
        }

        if (Schema::hasTable('lead_assignment_engines')) {
            Schema::table('lead_assignment_engines', function (Blueprint $table) {
                if ($this->indexExists('lead_assignment_engines', 'lead_assignments_status_employee_ca_index')) {
                    $table->dropIndex('lead_assignments_status_employee_ca_index');
                }
            });
        }

        if (Schema::hasTable('duplicate_attempts')) {
            Schema::table('duplicate_attempts', function (Blueprint $table) {
                if ($this->indexExists('duplicate_attempts', 'duplicate_attempts_created_at_index')) {
                    $table->dropIndex('duplicate_attempts_created_at_index');
                }
                if ($this->indexExists('duplicate_attempts', 'duplicate_attempts_type_created_index')) {
                    $table->dropIndex('duplicate_attempts_type_created_index');
                }
                if ($this->indexExists('duplicate_attempts', 'duplicate_attempts_employee_created_index')) {
                    $table->dropIndex('duplicate_attempts_employee_created_index');
                }
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            return Schema::hasIndex($table, $indexName);
        } catch (\Throwable) {
            return false;
        }
    }
};
