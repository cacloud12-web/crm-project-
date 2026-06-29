<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_name');
            $table->string('campaign_type');
            $table->string('audience_mode');
            $table->string('audience_label')->nullable();
            $table->json('audience_filters')->nullable();
            $table->json('selected_ca_ids')->nullable();
            $table->string('sender_id')->default('CACLDK');
            $table->text('message_template');
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status')->default('Draft');
            $table->string('performed_by')->default('System');
            $table->unsignedInteger('total_sms')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('queued_count')->default(0);
            $table->timestamps();
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('sms_campaigns')->cascadeOnDelete();
            $table->unsignedBigInteger('ca_id')->nullable();
            $table->string('mobile_no');
            $table->string('sender_id')->nullable();
            $table->text('message');
            $table->string('sms_status')->default('Queued');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamps();

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('sms_campaigns');
    }
};
