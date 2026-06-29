<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->string('campaign_name')->after('id');
            $table->string('campaign_type')->after('campaign_name');
            $table->string('audience_mode')->after('campaign_type');
            $table->string('audience_label')->nullable()->after('audience_mode');
            $table->json('audience_filters')->nullable()->after('audience_label');
            $table->json('selected_ca_ids')->nullable()->after('audience_filters');
            $table->string('subject')->after('selected_ca_ids');
            $table->text('body_template')->after('subject');
            $table->timestamp('scheduled_at')->nullable()->after('body_template');
            $table->string('status')->default('Draft')->after('scheduled_at');
            $table->string('performed_by')->default('System')->after('status');
            $table->unsignedInteger('total_emails')->default(0)->after('performed_by');
            $table->unsignedInteger('delivered_count')->default(0)->after('total_emails');
            $table->unsignedInteger('failed_count')->default(0)->after('delivered_count');
            $table->unsignedInteger('queued_count')->default(0)->after('failed_count');
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->foreignId('campaign_id')->after('id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->unsignedBigInteger('ca_id')->nullable()->after('campaign_id');
            $table->string('recipient_email')->after('ca_id');
            $table->string('subject')->after('recipient_email');
            $table->text('body')->after('subject');
            $table->string('email_status')->default('Queued')->after('body');
            $table->timestamp('queued_at')->nullable()->after('email_status');
            $table->timestamp('sent_at')->nullable()->after('queued_at');
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->text('failed_reason')->nullable()->after('delivered_at');

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropForeign(['ca_id']);
            $table->dropColumn([
                'campaign_id',
                'ca_id',
                'recipient_email',
                'subject',
                'body',
                'email_status',
                'queued_at',
                'sent_at',
                'delivered_at',
                'failed_reason',
            ]);
        });

        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'campaign_name',
                'campaign_type',
                'audience_mode',
                'audience_label',
                'audience_filters',
                'selected_ca_ids',
                'subject',
                'body_template',
                'scheduled_at',
                'status',
                'performed_by',
                'total_emails',
                'delivered_count',
                'failed_count',
                'queued_count',
            ]);
        });
    }
};
