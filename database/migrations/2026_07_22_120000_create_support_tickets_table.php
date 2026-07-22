<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_tickets')) {
            return;
        }

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();

            // Human-facing identifiers (UI serial + business ticket number).
            $table->unsignedBigInteger('serial_number')->unique();
            $table->string('ticket_number', 64)->unique();

            // Customer / reporter details (may originate from CA Cloud Desk or CRM).
            $table->string('customer_name');
            $table->string('organization_number', 64);
            $table->string('organization_name');
            $table->string('raised_by_name')->nullable();
            $table->foreignId('raised_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mobile_number', 30);
            // Populated only after successful mobile + organization verification.
            $table->string('email', 190)->nullable();

            // Organization / email verification audit (server-side; never trust frontend alone).
            $table->timestamp('customer_email_verified_at')->nullable();
            $table->string('verification_source', 32)->nullable();
            // unverified | verified | failed | skipped
            $table->string('email_verification_status', 32)->default('unverified');
            // Links ticket create to ticket_organization_lookups.correlation_id.
            $table->uuid('verification_correlation_id')->nullable();

            // Classification: issue | improvement | new_feature
            $table->string('problem_type', 32);
            $table->string('priority', 32)->default('normal');
            // open | under_review | closed
            $table->string('status', 32)->default('open');

            // Content.
            $table->text('description');
            $table->text('admin_remarks')->nullable();

            // Assignment (employee scope for visibility rules).
            $table->unsignedBigInteger('assigned_to_employee_id')->nullable();

            // Provenance: crm_employee | crm_client | ca_cloud_desk | api | system
            $table->string('created_via', 32)->default('crm_employee');

            // Integration / sync metadata.
            $table->string('source_system', 64)->default('crm');
            $table->string('external_ticket_id', 128)->nullable();
            $table->string('sync_status', 32)->default('pending');
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->json('external_payload')->nullable();

            // Hybrid notification summary (detailed history lives in ticket_notification_logs).
            // pending | queued | sent | failed | skipped
            $table->string('notification_email_status', 32)->default('pending');
            $table->string('notification_whatsapp_status', 32)->default('pending');

            // Audit (matches template / OCR conventions on users).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('assigned_to_employee_id')
                ->references('employee_id')
                ->on('employees')
                ->nullOnDelete();

            // Prevent duplicate inbound tickets from the same external system.
            // MySQL/MariaDB allow multiple NULL external_ticket_id rows (CRM-only tickets).
            $table->unique(
                ['source_system', 'external_ticket_id'],
                'support_tickets_source_external_unique',
            );

            $table->index('ticket_number', 'support_tickets_ticket_number_index');
            $table->index('organization_number', 'support_tickets_organization_number_index');
            $table->index('problem_type', 'support_tickets_problem_type_index');
            $table->index('status', 'support_tickets_status_index');
            $table->index('priority', 'support_tickets_priority_index');
            $table->index('assigned_to_employee_id', 'support_tickets_assigned_to_index');
            $table->index('created_via', 'support_tickets_created_via_index');
            $table->index('source_system', 'support_tickets_source_system_index');
            $table->index('external_updated_at', 'support_tickets_external_updated_at_index');
            $table->index('sync_status', 'support_tickets_sync_status_index');
            $table->index('email_verification_status', 'support_tickets_email_verification_status_index');
            $table->index('verification_correlation_id', 'support_tickets_verification_correlation_index');
            $table->index('created_at', 'support_tickets_created_at_index');
            $table->index('updated_at', 'support_tickets_updated_at_index');
            $table->index('raised_by_user_id', 'support_tickets_raised_by_user_index');
            $table->index(['status', 'priority'], 'support_tickets_status_priority_index');
            $table->index(['source_system', 'sync_status'], 'support_tickets_source_sync_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
