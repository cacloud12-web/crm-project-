<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_attachments')) {
            return;
        }

        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('ticket_comment_id')->nullable()->constrained('ticket_comments')->nullOnDelete();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // File metadata only — binary stored on disk (see storage_disk + storage_path).
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->string('checksum', 64)->nullable();

            // Integration trace (optional external attachment reference).
            $table->string('external_attachment_id', 128)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('support_ticket_id', 'ticket_attachments_ticket_index');
            $table->index('ticket_comment_id', 'ticket_attachments_comment_index');
            $table->index('uploaded_by', 'ticket_attachments_uploaded_by_index');
            $table->index('checksum', 'ticket_attachments_checksum_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
