<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('ca_masters', 'pan_no')) {
                $table->string('pan_no', 20)->nullable()->after('gst_no');
                $table->index('pan_no');
            }
            if (! Schema::hasColumn('ca_masters', 'normalized_email')) {
                $table->string('normalized_email', 255)->nullable()->after('email_id');
                $table->index('normalized_email');
            }
            if (! Schema::hasColumn('ca_masters', 'normalized_website')) {
                $table->string('normalized_website', 255)->nullable()->after('website');
                $table->index('normalized_website');
            }
            if (! Schema::hasColumn('ca_masters', 'google_place_id')) {
                $table->string('google_place_id', 128)->nullable()->after('normalized_website');
                $table->index('google_place_id');
            }
            if (! Schema::hasColumn('ca_masters', 'verified_by')) {
                $table->unsignedBigInteger('verified_by')->nullable()->after('created_by_employee_id');
                $table->foreign('verified_by')
                    ->references('employee_id')
                    ->on('employees')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('ca_masters', 'is_verified')) {
                $table->boolean('is_verified')->default(false)->after('verified_by');
            }
            if (! Schema::hasColumn('ca_masters', 'is_wrong_number')) {
                $table->boolean('is_wrong_number')->default(false)->after('is_verified');
            }
            if (! Schema::hasColumn('ca_masters', 'wrong_number_reason')) {
                $table->string('wrong_number_reason')->nullable()->after('is_wrong_number');
            }
        });

        Schema::table('duplicate_attempt_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('duplicate_attempt_logs', 'attempted_email')) {
                $table->string('attempted_email')->nullable()->after('attempted_mobile');
            }
            if (! Schema::hasColumn('duplicate_attempt_logs', 'attempted_gst')) {
                $table->string('attempted_gst', 50)->nullable()->after('attempted_email');
            }
            if (! Schema::hasColumn('duplicate_attempt_logs', 'attempted_website')) {
                $table->string('attempted_website')->nullable()->after('attempted_gst');
            }
            if (! Schema::hasColumn('duplicate_attempt_logs', 'attempted_pan')) {
                $table->string('attempted_pan', 20)->nullable()->after('attempted_gst');
            }
            if (! Schema::hasColumn('duplicate_attempt_logs', 'attempted_place_id')) {
                $table->string('attempted_place_id', 128)->nullable()->after('attempted_website');
            }
        });

        if (! Schema::hasTable('employee_productivity_logs')) {
            Schema::create('employee_productivity_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->date('log_date');
                $table->unsignedInteger('leads_assigned')->default(0);
                $table->unsignedInteger('unique_leads_added')->default(0);
                $table->unsignedInteger('duplicate_attempts')->default(0);
                $table->unsignedInteger('wrong_numbers')->default(0);
                $table->unsignedInteger('verified_leads')->default(0);
                $table->unsignedInteger('followups_completed')->default(0);
                $table->unsignedInteger('sms_failed')->default(0);
                $table->unsignedInteger('whatsapp_failed')->default(0);
                $table->unsignedInteger('email_failed')->default(0);
                $table->unsignedInteger('invalid_leads')->default(0);
                $table->integer('quality_score')->default(0);
                $table->unsignedInteger('rank')->nullable();
                $table->timestamps();

                $table->foreign('employee_id')
                    ->references('employee_id')
                    ->on('employees')
                    ->cascadeOnDelete();
                $table->unique(['employee_id', 'log_date']);
                $table->index('log_date');
            });
        }

        if (! Schema::hasTable('lead_quality_histories')) {
            Schema::create('lead_quality_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ca_id');
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('event_type', 50);
                $table->string('reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('recorded_at');
                $table->timestamps();

                $table->foreign('ca_id')
                    ->references('ca_id')
                    ->on('ca_masters')
                    ->cascadeOnDelete();
                $table->foreign('employee_id')
                    ->references('employee_id')
                    ->on('employees')
                    ->nullOnDelete();
                $table->index(['ca_id', 'event_type']);
                $table->index(['employee_id', 'recorded_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_quality_histories');
        Schema::dropIfExists('employee_productivity_logs');

        Schema::table('duplicate_attempt_logs', function (Blueprint $table) {
            foreach (['attempted_place_id', 'attempted_pan', 'attempted_website', 'attempted_gst', 'attempted_email'] as $column) {
                if (Schema::hasColumn('duplicate_attempt_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('ca_masters', function (Blueprint $table) {
            foreach (['wrong_number_reason', 'is_wrong_number', 'is_verified'] as $column) {
                if (Schema::hasColumn('ca_masters', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('ca_masters', 'verified_by')) {
                $table->dropForeign(['verified_by']);
                $table->dropColumn('verified_by');
            }
            foreach (['google_place_id', 'normalized_website', 'normalized_email', 'pan_no'] as $column) {
                if (Schema::hasColumn('ca_masters', $column)) {
                    $table->dropIndex([$column]);
                    $table->dropColumn($column);
                }
            }
        });
    }
};
