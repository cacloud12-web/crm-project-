<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_sync_logs')) {
            return;
        }

        Schema::create('ticket_sync_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('support_ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete();

            // Distinguishes integration operation type (not just HTTP direction).
            // ticket_inbound | ticket_outbound | organization_lookup | organization_verify | acknowledgement
            $table->string('sync_operation', 32);

            // inbound | outbound — retained for HTTP-oriented filtering alongside sync_operation.
            $table->string('direction', 16);
            $table->string('source_system', 64)->default('ca_cloud_desk');

            // Trace lookup → verify → ticket create without requiring a ticket row yet.
            $table->uuid('correlation_id')->nullable();
            $table->string('mobile_number', 30)->nullable();
            $table->string('organization_number', 64)->nullable();

            $table->string('endpoint', 512)->nullable();
            $table->string('http_method', 16)->nullable();
            $table->unsignedSmallInteger('http_status_code')->nullable();

            // success | failed | pending | acknowledged
            $table->string('status', 32)->default('pending');

            $table->string('external_ticket_id', 128)->nullable();
            $table->string('idempotency_key', 128)->nullable();

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Nullable idempotency_key: MySQL allows multiple NULLs; app must set key for integration events.
            $table->unique('idempotency_key', 'ticket_sync_logs_idempotency_key_unique');

            $table->index('support_ticket_id', 'ticket_sync_logs_ticket_index');
            $table->index('sync_operation', 'ticket_sync_logs_sync_operation_index');
            $table->index('correlation_id', 'ticket_sync_logs_correlation_id_index');
            $table->index('status', 'ticket_sync_logs_status_index');
            $table->index('source_system', 'ticket_sync_logs_source_system_index');
            $table->index('direction', 'ticket_sync_logs_direction_index');
            $table->index('mobile_number', 'ticket_sync_logs_mobile_number_index');
            $table->index('organization_number', 'ticket_sync_logs_organization_number_index');
            $table->index('created_at', 'ticket_sync_logs_created_at_index');
            $table->index(['source_system', 'status'], 'ticket_sync_logs_source_status_index');
            $table->index(['sync_operation', 'created_at'], 'ticket_sync_logs_operation_created_index');
            $table->index(['support_ticket_id', 'sync_operation', 'created_at'], 'ticket_sync_logs_ticket_operation_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sync_logs');
    }
};
