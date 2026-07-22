<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_organization_lookups')) {
            return;
        }

        Schema::create('ticket_organization_lookups', function (Blueprint $table) {
            $table->id();

            $table->string('mobile_number', 30);
            // Selected organization (populated after user selection / verification step).
            $table->string('organization_number', 64)->nullable();
            $table->string('organization_name')->nullable();

            // Organization list from lookup API — must not include email until verified.
            $table->json('organizations_payload')->nullable();

            // success | failed | not_configured
            $table->string('lookup_status', 32)->default('pending');
            // pending | verified | failed
            $table->string('verification_status', 32)->default('pending');

            // Populated only after successful organization_verify; never from lookup alone.
            $table->string('verified_email', 190)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // ca_cloud_desk | crm_cache | manual
            $table->string('lookup_source', 32)->default('ca_cloud_desk');

            // Shared trace id across lookup, verify, sync logs, and ticket create.
            $table->uuid('correlation_id')->unique();

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('mobile_number', 'ticket_org_lookups_mobile_number_index');
            $table->index('organization_number', 'ticket_org_lookups_organization_number_index');
            $table->index('lookup_status', 'ticket_org_lookups_lookup_status_index');
            $table->index('verification_status', 'ticket_org_lookups_verification_status_index');
            $table->index('expires_at', 'ticket_org_lookups_expires_at_index');
            $table->index(['mobile_number', 'verification_status'], 'ticket_org_lookups_mobile_verification_index');
            $table->index(['requested_by_user_id', 'created_at'], 'ticket_org_lookups_user_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_organization_lookups');
    }
};
