<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->string('provider_name')->default('SMS Alert')->after('id');
            $table->string('api_url')->default('https://www.smsalert.co.in/api/push.json')->after('provider_name');
            $table->string('api_key')->nullable()->after('api_url');
            $table->string('sender_id')->nullable()->after('api_key');
            $table->string('mode')->default('simulation')->after('sender_id');
        });

        if (Schema::hasTable('sms_settings') && DB::table('sms_settings')->count() === 0) {
            DB::table('sms_settings')->insert([
                'provider_name' => 'SMS Alert',
                'api_url' => 'https://www.smsalert.co.in/api/push.json',
                'api_key' => null,
                'sender_id' => null,
                'mode' => 'simulation',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->after('ca_id');
            $table->text('provider_response')->nullable()->after('failed_reason');
            $table->text('error_message')->nullable()->after('provider_response');

            $table->foreign('employee_id')
                ->references('employee_id')
                ->on('employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn(['employee_id', 'provider_response', 'error_message']);
        });

        Schema::table('sms_settings', function (Blueprint $table) {
            $table->dropColumn(['provider_name', 'api_url', 'api_key', 'sender_id', 'mode']);
        });
    }
};
