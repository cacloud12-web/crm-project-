<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_comments')) {
            return;
        }

        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            // CRM author (nullable for system / external / client replies).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name')->nullable();
            // employee | client | admin | system
            $table->string('author_type', 16)->default('employee');

            // reply | internal_note | system | client_reply (exact values enforced in config).
            $table->string('comment_type', 32)->default('reply');
            $table->text('body');

            // public | internal | client — complements is_internal for chat/reply visibility.
            $table->string('visibility', 16)->default('public');
            $table->boolean('is_internal')->default(false);

            $table->string('source_system', 64)->default('crm');
            $table->string('external_comment_id', 128)->nullable();

            // Optional structured metadata from integrations.
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Prevent duplicate inbound comments from the same external system.
            $table->unique(
                ['source_system', 'external_comment_id'],
                'ticket_comments_source_external_unique',
            );

            $table->index(['support_ticket_id', 'created_at'], 'ticket_comments_ticket_created_index');
            $table->index(['support_ticket_id', 'comment_type'], 'ticket_comments_ticket_type_index');
            $table->index(['support_ticket_id', 'visibility'], 'ticket_comments_ticket_visibility_index');
            $table->index('user_id', 'ticket_comments_user_id_index');
            $table->index('author_type', 'ticket_comments_author_type_index');
            $table->index('external_comment_id', 'ticket_comments_external_comment_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_comments');
    }
};
