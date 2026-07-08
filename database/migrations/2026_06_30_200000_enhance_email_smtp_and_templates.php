<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('email_settings', 'reply_to_email')) {
                $table->string('reply_to_email')->nullable()->after('from_name');
            }
            if (! Schema::hasColumn('email_settings', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('mode');
            }
            if (! Schema::hasColumn('email_settings', 'is_default')) {
                $table->boolean('is_default')->default(true)->after('is_active');
            }
            if (! Schema::hasColumn('email_settings', 'last_tested_at')) {
                $table->timestamp('last_tested_at')->nullable()->after('is_default');
            }
            if (! Schema::hasColumn('email_settings', 'last_test_status')) {
                $table->string('last_test_status')->nullable()->after('last_tested_at');
            }
            if (! Schema::hasColumn('email_settings', 'last_test_response')) {
                $table->text('last_test_response')->nullable()->after('last_test_status');
            }
        });

        Schema::table('email_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('email_campaigns', 'email_template_id')) {
                $table->unsignedBigInteger('email_template_id')->nullable()->after('body_template');
            }
        });

        Schema::table('email_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('email_logs', 'email_setting_id')) {
                $table->unsignedBigInteger('email_setting_id')->nullable()->after('campaign_id');
            }
            if (! Schema::hasColumn('email_logs', 'cc')) {
                $table->json('cc')->nullable()->after('recipient_email');
            }
            if (! Schema::hasColumn('email_logs', 'bcc')) {
                $table->json('bcc')->nullable()->after('cc');
            }
            if (! Schema::hasColumn('email_logs', 'attachments')) {
                $table->json('attachments')->nullable()->after('bcc');
            }
            if (! Schema::hasColumn('email_logs', 'is_html')) {
                $table->boolean('is_html')->default(true)->after('body');
            }
        });

        Schema::table('email_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('email_templates', 'slug')) {
                $table->string('slug')->nullable()->unique()->after('name');
            }
            if (! Schema::hasColumn('email_templates', 'description')) {
                $table->string('description')->nullable()->after('slug');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_settings', function (Blueprint $table) {
            foreach (['reply_to_email', 'is_active', 'is_default', 'last_tested_at', 'last_test_status', 'last_test_response'] as $column) {
                if (Schema::hasColumn('email_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('email_campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('email_campaigns', 'email_template_id')) {
                $table->dropColumn('email_template_id');
            }
        });

        Schema::table('email_logs', function (Blueprint $table) {
            foreach (['email_setting_id', 'cc', 'bcc', 'attachments', 'is_html'] as $column) {
                if (Schema::hasColumn('email_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('email_templates', function (Blueprint $table) {
            foreach (['slug', 'description'] as $column) {
                if (Schema::hasColumn('email_templates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
