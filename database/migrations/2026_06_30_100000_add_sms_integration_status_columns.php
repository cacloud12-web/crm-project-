<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('sms_settings', 'integration_status')) {
                $table->string('integration_status', 30)->default('not_configured')->after('is_active');
            }
            if (! Schema::hasColumn('sms_settings', 'last_tested_at')) {
                $table->timestamp('last_tested_at')->nullable()->after('integration_status');
            }
            if (! Schema::hasColumn('sms_settings', 'last_test_status')) {
                $table->string('last_test_status', 30)->nullable()->after('last_tested_at');
            }
            if (! Schema::hasColumn('sms_settings', 'last_test_response')) {
                $table->text('last_test_response')->nullable()->after('last_test_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            foreach (['integration_status', 'last_tested_at', 'last_test_status', 'last_test_response'] as $column) {
                if (Schema::hasColumn('sms_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
