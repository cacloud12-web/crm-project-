<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $campaignTables = [
        'email_campaigns',
        'sms_campaigns',
        'whatsapp_campaigns',
    ];

    public function up(): void
    {
        foreach ($this->campaignTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'campaign_uuid')) {
                    $blueprint->uuid('campaign_uuid')->nullable()->unique();
                }
                if (! Schema::hasColumn($table, 'created_by_user_id')) {
                    $blueprint->unsignedBigInteger('created_by_user_id')->nullable()->after('performed_by');
                }
                if (! Schema::hasColumn($table, 'sender_config_id')) {
                    $blueprint->unsignedBigInteger('sender_config_id')->nullable();
                }
                if (! Schema::hasColumn($table, 'sender_snapshot')) {
                    $blueprint->json('sender_snapshot')->nullable();
                }
                if (! Schema::hasColumn($table, 'template_snapshot')) {
                    $blueprint->json('template_snapshot')->nullable();
                }
                if (! Schema::hasColumn($table, 'status_history')) {
                    $blueprint->json('status_history')->nullable();
                }
                if (! Schema::hasColumn($table, 'pending_count')) {
                    $blueprint->unsignedInteger('pending_count')->default(0);
                }
                if (! Schema::hasColumn($table, 'invalid_count')) {
                    $blueprint->unsignedInteger('invalid_count')->default(0);
                }
                if (! Schema::hasColumn($table, 'duplicate_count')) {
                    $blueprint->unsignedInteger('duplicate_count')->default(0);
                }
                if (! Schema::hasColumn($table, 'bounce_count')) {
                    $blueprint->unsignedInteger('bounce_count')->default(0);
                }
                if (! Schema::hasColumn($table, 'retry_count')) {
                    $blueprint->unsignedInteger('retry_count')->default(0);
                }
                if (! Schema::hasColumn($table, 'paused_at')) {
                    $blueprint->timestamp('paused_at')->nullable();
                }
                if (! Schema::hasColumn($table, 'cancelled_at')) {
                    $blueprint->timestamp('cancelled_at')->nullable();
                }
                if (! Schema::hasColumn($table, 'completed_at')) {
                    $blueprint->timestamp('completed_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->campaignTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                foreach ([
                    'campaign_uuid',
                    'created_by_user_id',
                    'sender_config_id',
                    'sender_snapshot',
                    'template_snapshot',
                    'status_history',
                    'pending_count',
                    'invalid_count',
                    'duplicate_count',
                    'bounce_count',
                    'retry_count',
                    'paused_at',
                    'cancelled_at',
                    'completed_at',
                ] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }
};
