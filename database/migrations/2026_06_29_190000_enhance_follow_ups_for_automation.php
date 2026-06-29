<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            if (! Schema::hasColumn('follow_ups', 'outcome')) {
                $table->string('outcome')->nullable()->after('followup_type');
            }
            if (! Schema::hasColumn('follow_ups', 'priority')) {
                $table->string('priority')->default('Normal')->after('status');
            }
            if (! Schema::hasColumn('follow_ups', 'created_by_user_id')) {
                $table->unsignedBigInteger('created_by_user_id')->nullable()->after('employee_id');
            }
            if (! Schema::hasColumn('follow_ups', 'parent_followup_id')) {
                $table->unsignedBigInteger('parent_followup_id')->nullable()->after('created_by_user_id');
            }
            if (! Schema::hasColumn('follow_ups', 'sequence_step')) {
                $table->unsignedSmallInteger('sequence_step')->nullable()->after('parent_followup_id');
            }
            if (! Schema::hasColumn('follow_ups', 'is_auto_generated')) {
                $table->boolean('is_auto_generated')->default(false)->after('sequence_step');
            }
            if (! Schema::hasColumn('follow_ups', 'source')) {
                $table->string('source')->default('manual')->after('is_auto_generated');
            }
            if (! Schema::hasColumn('follow_ups', 'is_rescheduled')) {
                $table->boolean('is_rescheduled')->default(false)->after('source');
            }
            if (! Schema::hasColumn('follow_ups', 'rescheduled_at')) {
                $table->timestamp('rescheduled_at')->nullable()->after('is_rescheduled');
            }
            if (! Schema::hasColumn('follow_ups', 'rescheduled_by')) {
                $table->string('rescheduled_by')->nullable()->after('rescheduled_at');
            }
            if (! Schema::hasColumn('follow_ups', 'reschedule_reason')) {
                $table->text('reschedule_reason')->nullable()->after('rescheduled_by');
            }
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            if (Schema::hasColumn('follow_ups', 'created_by_user_id')) {
                $table->foreign('created_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
            if (Schema::hasColumn('follow_ups', 'parent_followup_id')) {
                $table->foreign('parent_followup_id')
                    ->references('followup_id')
                    ->on('follow_ups')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            foreach ([
                'created_by_user_id',
                'parent_followup_id',
            ] as $fk) {
                if (Schema::hasColumn('follow_ups', $fk)) {
                    $table->dropForeign([$fk]);
                }
            }
            foreach ([
                'outcome',
                'priority',
                'created_by_user_id',
                'parent_followup_id',
                'sequence_step',
                'is_auto_generated',
                'source',
                'is_rescheduled',
                'rescheduled_at',
                'rescheduled_by',
                'reschedule_reason',
            ] as $col) {
                if (Schema::hasColumn('follow_ups', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
