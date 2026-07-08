<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_settings', 'webhook_verify_token')) {
                $table->text('webhook_verify_token')->nullable()->after('access_token');
            }
            if (! Schema::hasColumn('whatsapp_settings', 'integration_status')) {
                $table->string('integration_status', 32)->default('not_configured')->after('is_active');
            }
            if (! Schema::hasColumn('whatsapp_settings', 'last_tested_at')) {
                $table->timestamp('last_tested_at')->nullable()->after('integration_status');
            }
            if (! Schema::hasColumn('whatsapp_settings', 'last_test_status')) {
                $table->string('last_test_status', 32)->nullable()->after('last_tested_at');
            }
            if (! Schema::hasColumn('whatsapp_settings', 'last_test_response')) {
                $table->text('last_test_response')->nullable()->after('last_test_status');
            }
            if (! Schema::hasColumn('whatsapp_settings', 'last_successful_send_at')) {
                $table->timestamp('last_successful_send_at')->nullable()->after('last_test_response');
            }
            if (! Schema::hasColumn('whatsapp_settings', 'test_mobile_number')) {
                $table->string('test_mobile_number', 20)->nullable()->after('last_successful_send_at');
            }
        });

        Schema::table('wa_message_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('wa_message_logs', 'meta_message_id')) {
                $table->string('meta_message_id', 128)->nullable()->after('message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wa_message_logs', function (Blueprint $table) {
            if (Schema::hasColumn('wa_message_logs', 'meta_message_id')) {
                $table->dropColumn('meta_message_id');
            }
        });

        Schema::table('whatsapp_settings', function (Blueprint $table) {
            foreach ([
                'webhook_verify_token',
                'integration_status',
                'last_tested_at',
                'last_test_status',
                'last_test_response',
                'last_successful_send_at',
                'test_mobile_number',
            ] as $column) {
                if (Schema::hasColumn('whatsapp_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
