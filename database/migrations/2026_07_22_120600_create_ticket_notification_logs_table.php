<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_notification_logs')) {
            return;
        }

        Schema::create('ticket_notification_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            // email | whatsapp
            $table->string('channel', 16);
            // ticket_created | status_changed | reply_added | ticket_closed
            $table->string('event_type', 32);
            // client | admin
            $table->string('recipient_type', 16);
            $table->string('recipient_address', 190);

            // pending | queued | sent | failed | skipped
            $table->string('status', 32)->default('pending');
            $table->string('provider_message_id', 128)->nullable();

            $table->json('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index('support_ticket_id', 'ticket_notification_logs_ticket_index');
            $table->index('channel', 'ticket_notification_logs_channel_index');
            $table->index('event_type', 'ticket_notification_logs_event_type_index');
            $table->index('status', 'ticket_notification_logs_status_index');
            $table->index(['support_ticket_id', 'channel'], 'ticket_notification_logs_ticket_channel_index');
            $table->index(['status', 'created_at'], 'ticket_notification_logs_status_created_index');
            $table->index(['support_ticket_id', 'event_type', 'created_at'], 'ticket_notification_logs_ticket_event_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_notification_logs');
    }
};
