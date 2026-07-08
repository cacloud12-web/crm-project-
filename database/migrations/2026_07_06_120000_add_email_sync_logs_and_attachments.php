<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_sync_logs')) {
            Schema::create('email_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('email_setting_id');
                $table->string('status', 16)->default('running');
                $table->unsignedInteger('messages_fetched')->default(0);
                $table->unsignedInteger('messages_stored')->default(0);
                $table->unsignedInteger('leads_matched')->default(0);
                $table->text('error_message')->nullable();
                $table->json('details')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['email_setting_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('email_attachments')) {
            Schema::create('email_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('email_inbound_message_id');
                $table->string('filename');
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->string('storage_path')->nullable();
                $table->timestamps();

                $table->index('email_inbound_message_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
        Schema::dropIfExists('email_sync_logs');
    }
};
