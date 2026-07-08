<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->string('dlt_template_id', 30)->nullable()->after('sender_id');
        });

        Schema::table('sms_templates', function (Blueprint $table) {
            $table->string('dlt_template_id', 30)->nullable()->after('sender_id');
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->string('dlt_template_id', 30)->nullable()->after('template_name');
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropColumn('dlt_template_id');
        });

        Schema::table('sms_templates', function (Blueprint $table) {
            $table->dropColumn('dlt_template_id');
        });

        Schema::table('sms_settings', function (Blueprint $table) {
            $table->dropColumn('dlt_template_id');
        });
    }
};
