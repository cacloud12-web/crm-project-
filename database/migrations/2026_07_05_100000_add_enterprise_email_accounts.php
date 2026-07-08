<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('email_settings', 'display_name')) {
                $table->string('display_name')->nullable()->after('from_name');
            }
            if (! Schema::hasColumn('email_settings', 'imap_host')) {
                $table->string('imap_host')->nullable()->after('smtp_encryption');
            }
            if (! Schema::hasColumn('email_settings', 'imap_port')) {
                $table->unsignedSmallInteger('imap_port')->nullable()->after('imap_host');
            }
            if (! Schema::hasColumn('email_settings', 'imap_encryption')) {
                $table->string('imap_encryption', 16)->nullable()->after('imap_port');
            }
            if (! Schema::hasColumn('email_settings', 'imap_username')) {
                $table->string('imap_username')->nullable()->after('imap_encryption');
            }
            if (! Schema::hasColumn('email_settings', 'imap_password')) {
                $table->text('imap_password')->nullable()->after('imap_username');
            }
            if (! Schema::hasColumn('email_settings', 'imap_enabled')) {
                $table->boolean('imap_enabled')->default(false)->after('imap_password');
            }
            if (! Schema::hasColumn('email_settings', 'smtp_last_tested_at')) {
                $table->timestamp('smtp_last_tested_at')->nullable()->after('last_test_response');
            }
            if (! Schema::hasColumn('email_settings', 'smtp_last_test_status')) {
                $table->string('smtp_last_test_status')->nullable()->after('smtp_last_tested_at');
            }
            if (! Schema::hasColumn('email_settings', 'smtp_last_test_response')) {
                $table->text('smtp_last_test_response')->nullable()->after('smtp_last_test_status');
            }
            if (! Schema::hasColumn('email_settings', 'imap_last_tested_at')) {
                $table->timestamp('imap_last_tested_at')->nullable()->after('smtp_last_test_response');
            }
            if (! Schema::hasColumn('email_settings', 'imap_last_test_status')) {
                $table->string('imap_last_test_status')->nullable()->after('imap_last_tested_at');
            }
            if (! Schema::hasColumn('email_settings', 'imap_last_test_response')) {
                $table->text('imap_last_test_response')->nullable()->after('imap_last_test_status');
            }
            if (! Schema::hasColumn('email_settings', 'last_imap_sync_at')) {
                $table->timestamp('last_imap_sync_at')->nullable()->after('imap_last_test_response');
            }
        });

        Schema::table('email_settings', function (Blueprint $table) {
            if (Schema::hasColumn('email_settings', 'from_email')) {
                $table->unique('from_email');
            }
        });

        if (! Schema::hasTable('email_inbound_messages')) {
            Schema::create('email_inbound_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('email_setting_id');
                $table->unsignedBigInteger('ca_id')->nullable();
                $table->unsignedBigInteger('email_log_id')->nullable();
                $table->string('folder', 64)->default('INBOX');
                $table->string('direction', 16)->default('inbound');
                $table->string('message_id')->nullable();
                $table->string('in_reply_to')->nullable();
                $table->text('references_header')->nullable();
                $table->string('from_email');
                $table->string('to_email')->nullable();
                $table->string('subject')->nullable();
                $table->longText('body_text')->nullable();
                $table->longText('body_html')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('matched_at')->nullable();
                $table->json('raw_headers')->nullable();
                $table->timestamps();

                $table->index(['email_setting_id', 'folder']);
                $table->index('ca_id');
                $table->index('message_id');
                $table->unique(['email_setting_id', 'message_id']);
            });
        }

        Schema::table('email_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('email_logs', 'message_id')) {
                $table->string('message_id')->nullable()->after('subject');
            }
            if (! Schema::hasColumn('email_logs', 'direction')) {
                $table->string('direction', 16)->default('outbound')->after('email_status');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_inbound_messages');

        Schema::table('email_logs', function (Blueprint $table) {
            foreach (['message_id', 'direction'] as $column) {
                if (Schema::hasColumn('email_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('email_settings', function (Blueprint $table) {
            if (Schema::hasColumn('email_settings', 'from_email')) {
                $table->dropUnique(['from_email']);
            }
            foreach ([
                'display_name', 'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password',
                'imap_enabled', 'smtp_last_tested_at', 'smtp_last_test_status', 'smtp_last_test_response',
                'imap_last_tested_at', 'imap_last_test_status', 'imap_last_test_response', 'last_imap_sync_at',
            ] as $column) {
                if (Schema::hasColumn('email_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
