<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->string('campaign_name')->after('id');
            $table->string('campaign_type')->after('campaign_name');
            $table->string('audience_mode')->after('campaign_type');
            $table->string('audience_label')->nullable()->after('audience_mode');
            $table->json('audience_filters')->nullable()->after('audience_label');
            $table->json('selected_ca_ids')->nullable()->after('audience_filters');
            $table->text('message_template')->after('selected_ca_ids');
            $table->timestamp('scheduled_at')->nullable()->after('message_template');
            $table->string('status')->default('Draft')->after('scheduled_at');
            $table->string('performed_by')->default('System')->after('status');
            $table->unsignedInteger('total_messages')->default(0)->after('performed_by');
            $table->unsignedInteger('delivered_count')->default(0)->after('total_messages');
            $table->unsignedInteger('failed_count')->default(0)->after('delivered_count');
            $table->unsignedInteger('queued_count')->default(0)->after('failed_count');
        });

        Schema::table('wa_message_logs', function (Blueprint $table) {
            $table->foreignId('campaign_id')->after('id')->constrained('whatsapp_campaigns')->cascadeOnDelete();
            $table->unsignedBigInteger('ca_id')->nullable()->after('campaign_id');
            $table->string('mobile_no')->after('ca_id');
            $table->text('message')->after('mobile_no');
            $table->string('message_status')->default('Queued')->after('message');
            $table->timestamp('queued_at')->nullable()->after('message_status');
            $table->timestamp('sent_at')->nullable()->after('queued_at');
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->text('failed_reason')->nullable()->after('delivered_at');

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wa_message_logs', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropForeign(['ca_id']);
            $table->dropColumn([
                'campaign_id',
                'ca_id',
                'mobile_no',
                'message',
                'message_status',
                'queued_at',
                'sent_at',
                'delivered_at',
                'failed_reason',
            ]);
        });

        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'campaign_name',
                'campaign_type',
                'audience_mode',
                'audience_label',
                'audience_filters',
                'selected_ca_ids',
                'message_template',
                'scheduled_at',
                'status',
                'performed_by',
                'total_messages',
                'delivered_count',
                'failed_count',
                'queued_count',
            ]);
        });
    }
};
