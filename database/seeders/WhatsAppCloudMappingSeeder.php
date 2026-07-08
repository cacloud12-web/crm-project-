<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

class WhatsAppCloudMappingSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'template_name' => 'demo_confirmation',
                'language_code' => 'en',
                'category' => 'UTILITY',
                'body_template' => 'Hello {{name}}, your demo for {{firm_name}} is scheduled on {{demo_date}} at {{demo_time}}. Contact: {{employee_name}}',
            ],
            [
                'template_name' => 'demo_reminder',
                'language_code' => 'en',
                'category' => 'UTILITY',
                'body_template' => 'Reminder: Demo for {{firm_name}} ({{city}}, {{state}}) on {{demo_date}} at {{demo_time}}.',
            ],
            [
                'template_name' => 'brochure_share',
                'language_code' => 'en',
                'category' => 'MARKETING',
                'body_template' => 'Hi {{name}}, sharing product details for {{firm_name}}. Reach us at {{mobile}}.',
            ],
            [
                'template_name' => 'task_customermp2et391nk',
                'display_name' => 'task_customermp2et391nk',
                'language_code' => 'en_US',
                'category' => 'UTILITY',
                'body_template' => 'Dear Mr. {{1}}, A new task titled "{{2}}" has been created for you on {{3}}. 👨‍💼 Assigned Staff: {{4}} 📅 Expected Completion: {{5}} You can track the task progress and share required documents via your dashboard. — CA CloudDesk - Demo Account',
                'meta_api_name' => 'task_customermp2et391nk',
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
                            'link' => config('whatsapp_cloud.default_header_documents.task_customermp2et391nk.link'),
                            'filename' => config('whatsapp_cloud.default_header_documents.task_customermp2et391nk.filename'),
                        ],
                    ],
                    'body_parameters' => ['{{1}}', '{{2}}', '{{3}}', '{{4}}', '{{5}}'],
                ],
            ],
            [
                'template_name' => 'task_scheduled_reminder',
                'display_name' => 'Task Status Reminder',
                'language_code' => 'en',
                'category' => 'UTILITY',
                'body_template' => 'Hello {{name}}, your task "{{task_name}}" is scheduled on {{scheduled_date}} at {{scheduled_time}}. Status: {{task_status}}.',
            ],
            [
                'template_name' => 'company_registration_docs',
                'display_name' => 'Company Registration Docs (DLT)',
                'language_code' => 'en',
                'category' => 'UTILITY',
                'body_template' => 'Hi {{1}}, please send the required docs for company registration. Need help? Reach us. -{{2}} lawseva',
                'variable_map' => [
                    '{{1}}' => 'ca_name',
                    '{{2}}' => 'static:LawSeva',
                ],
            ],
        ];

        foreach ($templates as $template) {
            $variableMap = $template['variable_map'] ?? config('whatsapp_cloud.template_variables');

            MessageTemplate::query()->updateOrCreate(
                [
                    'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                    'template_name' => $template['template_name'],
                    'language_code' => $template['language_code'],
                ],
                [
                    'body_template' => $template['body_template'],
                    'display_name' => $template['display_name'] ?? null,
                    'meta_api_name' => $template['meta_api_name'] ?? null,
                    'status' => MessageTemplate::STATUS_APPROVED,
                    'category' => $template['category'],
                    'variable_map' => $variableMap,
                    'is_active' => true,
                ],
            );
        }
    }
}
