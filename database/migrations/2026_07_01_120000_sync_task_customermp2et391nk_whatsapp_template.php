<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'task_customermp2et391nk',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'task_customermp2et391nk',
                'display_name' => 'Task Created Notification',
                'body_template' => <<<'BODY'
Dear Mr. Ramesh Gupta,

A new task titled "Filing GSTR" has been created for you on 24-June-2025.

Assigned Staff: Vikash, Nitish
Expected Completion: 24-June-2025

You can track the task progress and share required documents via your dashboard.
BODY,
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'variable_map' => null,
                'meta_components' => null,
                'is_active' => true,
            ],
        );

        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'task_scheduled_reminder')
            ->where('language_code', 'en')
            ->update(['meta_api_name' => 'task_customermp2et391nk']);
    }

    public function down(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'task_customermp2et391nk')
            ->where('language_code', 'en')
            ->delete();
    }
};
