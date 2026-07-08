<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_campaigns', 'message_template_id')) {
                $table->foreignId('message_template_id')
                    ->nullable()
                    ->after('message_template')
                    ->constrained('message_templates')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('whatsapp_campaigns', 'template_name')) {
                $table->string('template_name')->nullable()->after('message_template_id');
            }
            if (! Schema::hasColumn('whatsapp_campaigns', 'language_code')) {
                $table->string('language_code', 12)->nullable()->after('template_name');
            }
            if (! Schema::hasColumn('whatsapp_campaigns', 'api_version')) {
                $table->string('api_version', 20)->nullable()->after('language_code');
            }
            if (! Schema::hasColumn('whatsapp_campaigns', 'payload_generated_at')) {
                $table->timestamp('payload_generated_at')->nullable()->after('api_version');
            }
        });

        Schema::table('wa_message_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('wa_message_logs', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->after('ca_id');
                $table->foreign('employee_id')->references('employee_id')->on('employees')->nullOnDelete();
            }
            if (! Schema::hasColumn('wa_message_logs', 'template_name')) {
                $table->string('template_name')->nullable()->after('employee_id');
            }
            if (! Schema::hasColumn('wa_message_logs', 'language_code')) {
                $table->string('language_code', 12)->nullable()->after('template_name');
            }
            if (! Schema::hasColumn('wa_message_logs', 'api_payload')) {
                $table->json('api_payload')->nullable()->after('message');
            }
            if (! Schema::hasColumn('wa_message_logs', 'provider_response')) {
                $table->json('provider_response')->nullable()->after('api_payload');
            }
            if (! Schema::hasColumn('wa_message_logs', 'error_message')) {
                $table->text('error_message')->nullable()->after('provider_response');
            }
            if (! Schema::hasColumn('wa_message_logs', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('delivered_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wa_message_logs', function (Blueprint $table) {
            if (Schema::hasColumn('wa_message_logs', 'employee_id')) {
                $table->dropForeign(['employee_id']);
            }
            foreach ([
                'employee_id',
                'template_name',
                'language_code',
                'api_payload',
                'provider_response',
                'error_message',
                'read_at',
            ] as $column) {
                if (Schema::hasColumn('wa_message_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_campaigns', 'message_template_id')) {
                $table->dropForeign(['message_template_id']);
            }
            foreach ([
                'message_template_id',
                'template_name',
                'language_code',
                'api_version',
                'payload_generated_at',
            ] as $column) {
                if (Schema::hasColumn('whatsapp_campaigns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
