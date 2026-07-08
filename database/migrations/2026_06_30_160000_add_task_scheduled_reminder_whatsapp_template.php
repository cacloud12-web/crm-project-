<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('message_templates', 'display_name')) {
            Schema::table('message_templates', function (Blueprint $table) {
                $table->string('display_name')->nullable()->after('template_name');
            });
        }

        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'task_scheduled_reminder',
                'language_code' => 'en',
            ],
            [
                'display_name' => 'Task Status Reminder',
                'body_template' => 'Hello {{name}}, your task "{{task_name}}" is scheduled on {{scheduled_date}} at {{scheduled_time}}. Status: {{task_status}}.',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                    '{{task_name}}' => 'task_name',
                    '{{scheduled_date}}' => 'scheduled_date',
                    '{{scheduled_time}}' => 'scheduled_time',
                    '{{task_status}}' => 'task_status',
                ],
                'meta_components' => [
                    'body_parameters' => ['name', 'task_name', 'scheduled_date', 'scheduled_time', 'task_status'],
                ],
                'is_active' => true,
            ],
        );
    }

    public function down(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'task_scheduled_reminder')
            ->where('language_code', 'en')
            ->delete();

        if (Schema::hasColumn('message_templates', 'display_name')) {
            Schema::table('message_templates', function (Blueprint $table) {
                $table->dropColumn('display_name');
            });
        }
    }
};
