<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'task_customermp2et391nk')
            ->delete();

        $documentLink = config('whatsapp_cloud.default_header_documents.task_customermp2et391nk.link');
        $documentFilename = config('whatsapp_cloud.default_header_documents.task_customermp2et391nk.filename', 'task-notification.pdf');

        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'task_customermp2et391nk',
                'language_code' => 'en_US',
            ],
            [
                'meta_api_name' => 'task_customermp2et391nk',
                'display_name' => 'task_customermp2et391nk',
                'body_template' => <<<'BODY'
Dear Mr. {{1}},
A new task titled "{{2}}" has been created for you on {{3}}.
👨‍💼 Assigned Staff: {{4}}
📅 Expected Completion: {{5}}

You can track the task progress and share required documents via your dashboard.
— CA CloudDesk - Demo Account
BODY,
                'status' => MessageTemplate::STATUS_APPROVED,
                'meta_status' => 'APPROVED',
                'category' => 'UTILITY',
                'variable_map' => [
                    '{{1}}' => 'ca_name',
                    '{{2}}' => 'task_name',
                    '{{3}}' => 'task_date',
                    '{{4}}' => 'assigned_staff',
                    '{{5}}' => 'expected_completion',
                ],
                'meta_components' => [
                    'header' => [
                        'type' => 'document',
                        'document' => [
                            'link' => $documentLink,
                            'filename' => $documentFilename,
                        ],
                    ],
                    'body_parameters' => ['{{1}}', '{{2}}', '{{3}}', '{{4}}', '{{5}}'],
                ],
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
            ->where('language_code', 'en_US')
            ->delete();
    }
};
