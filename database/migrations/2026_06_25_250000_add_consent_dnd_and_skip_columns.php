<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_trackings', function (Blueprint $table) {
            $table->unsignedBigInteger('ca_id')->after('id');
            $table->string('consent_type')->after('ca_id');
            $table->string('consent_status')->default('No')->after('consent_type');
            $table->timestamp('consent_date')->nullable()->after('consent_status');

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
            $table->unique(['ca_id', 'consent_type']);
        });

        Schema::table('dnd_management', function (Blueprint $table) {
            $table->unsignedBigInteger('ca_id')->nullable()->after('id');
            $table->string('mobile_no')->nullable()->after('ca_id');
            $table->string('email_id')->nullable()->after('mobile_no');
            $table->string('dnd_type')->after('email_id');
            $table->text('reason')->nullable()->after('dnd_type');
            $table->string('added_by')->default('System')->after('reason');
            $table->timestamp('added_at')->nullable()->after('added_by');

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->nullOnDelete();
            $table->index(['ca_id', 'dnd_type']);
            $table->index('mobile_no');
            $table->index('email_id');
        });

        foreach (['whatsapp_campaigns', 'email_campaigns', 'sms_campaigns'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedInteger('skipped_count')->default(0)->after('queued_count');
            });
        }
    }

    public function down(): void
    {
        foreach (['whatsapp_campaigns', 'email_campaigns', 'sms_campaigns'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('skipped_count');
            });
        }

        Schema::table('dnd_management', function (Blueprint $table) {
            $table->dropForeign(['ca_id']);
            $table->dropColumn([
                'ca_id',
                'mobile_no',
                'email_id',
                'dnd_type',
                'reason',
                'added_by',
                'added_at',
            ]);
        });

        Schema::table('consent_trackings', function (Blueprint $table) {
            $table->dropForeign(['ca_id']);
            $table->dropColumn([
                'ca_id',
                'consent_type',
                'consent_status',
                'consent_date',
            ]);
        });
    }
};
