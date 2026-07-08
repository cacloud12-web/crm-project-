<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('email_campaigns', 'valid_emails_count')) {
                $table->unsignedInteger('valid_emails_count')->default(0)->after('total_emails');
            }
            if (! Schema::hasColumn('email_campaigns', 'invalid_emails_count')) {
                $table->unsignedInteger('invalid_emails_count')->default(0)->after('valid_emails_count');
            }
            if (! Schema::hasColumn('email_campaigns', 'duplicate_emails_count')) {
                $table->unsignedInteger('duplicate_emails_count')->default(0)->after('invalid_emails_count');
            }
            if (! Schema::hasColumn('email_campaigns', 'invalid_domain_count')) {
                $table->unsignedInteger('invalid_domain_count')->default(0)->after('duplicate_emails_count');
            }
            if (! Schema::hasColumn('email_campaigns', 'sent_count')) {
                $table->unsignedInteger('sent_count')->default(0)->after('delivered_count');
            }
            if (! Schema::hasColumn('email_campaigns', 'delivery_dispatch_token')) {
                $table->uuid('delivery_dispatch_token')->nullable()->unique()->after('status');
            }
            if (! Schema::hasColumn('email_campaigns', 'delivery_started_at')) {
                $table->timestamp('delivery_started_at')->nullable()->after('delivery_dispatch_token');
            }
            if (! Schema::hasColumn('email_campaigns', 'delivery_completed_at')) {
                $table->timestamp('delivery_completed_at')->nullable()->after('delivery_started_at');
            }
        });

        Schema::table('email_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('email_logs', 'smtp_error')) {
                $table->text('smtp_error')->nullable()->after('error_message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            foreach ([
                'valid_emails_count',
                'invalid_emails_count',
                'duplicate_emails_count',
                'invalid_domain_count',
                'sent_count',
                'delivery_dispatch_token',
                'delivery_started_at',
                'delivery_completed_at',
            ] as $column) {
                if (Schema::hasColumn('email_campaigns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('email_logs', function (Blueprint $table) {
            if (Schema::hasColumn('email_logs', 'smtp_error')) {
                $table->dropColumn('smtp_error');
            }
        });
    }
};
