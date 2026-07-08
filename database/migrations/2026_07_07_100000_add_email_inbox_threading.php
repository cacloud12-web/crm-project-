<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_threads')) {
            Schema::create('email_threads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('email_setting_id');
                $table->unsignedBigInteger('ca_id')->nullable();
                $table->string('thread_key')->index();
                $table->string('subject')->nullable();
                $table->string('participant_email')->nullable();
                $table->unsignedInteger('message_count')->default(0);
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();

                $table->unique(['email_setting_id', 'thread_key']);
                $table->index('ca_id');
            });
        }

        Schema::table('email_inbound_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('email_inbound_messages', 'email_thread_id')) {
                $table->unsignedBigInteger('email_thread_id')->nullable()->after('email_log_id');
            }
            if (! Schema::hasColumn('email_inbound_messages', 'imap_uid')) {
                $table->unsignedBigInteger('imap_uid')->nullable()->after('folder');
            }
            if (! Schema::hasColumn('email_inbound_messages', 'is_read')) {
                $table->boolean('is_read')->default(false)->after('matched_at');
            }
            if (! Schema::hasColumn('email_inbound_messages', 'match_status')) {
                $table->string('match_status', 16)->default('unmatched')->after('is_read');
            }
        });

        Schema::table('email_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('email_logs', 'reply_received_at')) {
                $table->timestamp('reply_received_at')->nullable()->after('bounced_at');
            }
            if (! Schema::hasColumn('email_logs', 'reply_from')) {
                $table->string('reply_from')->nullable()->after('reply_received_at');
            }
            if (! Schema::hasColumn('email_logs', 'reply_preview')) {
                $table->text('reply_preview')->nullable()->after('reply_from');
            }
        });

        Schema::table('email_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('email_settings', 'imap_sync_state')) {
                $table->json('imap_sync_state')->nullable()->after('last_imap_sync_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_settings', function (Blueprint $table) {
            if (Schema::hasColumn('email_settings', 'imap_sync_state')) {
                $table->dropColumn('imap_sync_state');
            }
        });

        Schema::table('email_logs', function (Blueprint $table) {
            foreach (['reply_received_at', 'reply_from', 'reply_preview'] as $column) {
                if (Schema::hasColumn('email_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('email_inbound_messages', function (Blueprint $table) {
            foreach (['email_thread_id', 'imap_uid', 'is_read', 'match_status'] as $column) {
                if (Schema::hasColumn('email_inbound_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('email_threads');
    }
};
