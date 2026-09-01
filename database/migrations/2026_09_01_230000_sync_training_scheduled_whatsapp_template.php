<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $metaStatusPayload = [
            'name' => 'training_scheduled',
            'status' => 'APPROVED',
            'language' => 'en',
            'parameter_format' => 'NAMED',
            'components' => [
                [
                    'type' => 'HEADER',
                    'format' => 'TEXT',
                    'text' => 'Training Scheduled',
                ],
                [
                    'type' => 'BODY',
                    'text' => "Hello {{name}},\n\nYour CA Cloud Desk training session has been scheduled successfully.\n\nDate: {{training_date}}\nTime: {{training_time}}\nJoin here: {{meeting_link}}\n\nOur team will guide you through the key features and help you use CA Cloud Desk effectively.\n\nWe look forward to seeing you.",
                    'example' => [
                        'body_text_named_params' => [
                            ['param_name' => 'name', 'example' => 'CA Ravi Kumar'],
                            ['param_name' => 'training_date', 'example' => '01-Sep-2026'],
                            ['param_name' => 'training_time', 'example' => '10:30 AM'],
                            ['param_name' => 'meeting_link', 'example' => 'https://meet.google.com/ouq-sxne-jwn'],
                        ],
                    ],
                ],
                [
                    'type' => 'FOOTER',
                    'text' => 'CaCloudDesk',
                ],
                [
                    'type' => 'BUTTONS',
                    'buttons' => [
                        [
                            'type' => 'URL',
                            'text' => 'Visit website',
                            'url' => 'https://caclouddesk.com/',
                        ],
                        [
                            'type' => 'PHONE_NUMBER',
                            'text' => 'Call phone number',
                            'phone_number' => '+919818092280',
                        ],
                    ],
                ],
            ],
        ];

        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'training_scheduled',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'training_scheduled',
                'meta_template_id' => '1398004569061958',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Training Scheduled',
                'header' => 'Training Scheduled',
                'body_template' => <<<'BODY'
Hello {{name}},

Your CA Cloud Desk training session has been scheduled successfully.

Date: {{training_date}}
Time: {{training_time}}
Join here: {{meeting_link}}

Our team will guide you through the key features and help you use CA Cloud Desk effectively.

We look forward to seeing you.
BODY,
                'footer' => 'CaCloudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                    '{{training_date}}' => 'scheduled_date',
                    '{{training_time}}' => 'scheduled_time',
                    '{{meeting_link}}' => 'meeting_link',
                ],
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => [
                        'name',
                        'training_date',
                        'training_time',
                        'meeting_link',
                    ],
                    'meta_registered_body_parameters' => [
                        'name',
                        'training_date',
                        'training_time',
                        'meeting_link',
                    ],
                    'body_placeholder_parameters' => [
                        'name',
                        'training_date',
                        'training_time',
                        'meeting_link',
                    ],
                    'sample' => [
                        'name' => 'CA Ravi Kumar',
                        'training_date' => '01-Sep-2026',
                        'training_time' => '10:30 AM',
                        'meeting_link' => 'https://meet.google.com/ouq-sxne-jwn',
                    ],
                ],
                'meta_status_payload' => $metaStatusPayload,
                'is_active' => true,
            ],
        );
    }

    public function down(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'training_scheduled')
            ->where('language_code', 'en')
            ->delete();
    }
};
