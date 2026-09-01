<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $metaStatusPayload = [
            'name' => 'onboarding_scheduled',
            'status' => 'APPROVED',
            'language' => 'en',
            'parameter_format' => 'NAMED',
            'components' => [
                [
                    'type' => 'HEADER',
                    'format' => 'TEXT',
                    'text' => 'Onboarding Scheduled',
                ],
                [
                    'type' => 'BODY',
                    'text' => "Hello {{name}},\n\nYour CA Cloud Desk onboarding session has been scheduled successfully.\n\nDate: {{onboarding_date}}\nTime: {{onboarding_time}}\nJoin here: {{meeting_link}}\n\nOur team will guide you through the initial setup and help you get started with CA Cloud Desk.\n\nSee you at the scheduled time.",
                    'example' => [
                        'body_text_named_params' => [
                            ['param_name' => 'name', 'example' => 'CA Ravi Kumar'],
                            ['param_name' => 'onboarding_date', 'example' => '01-Sep-2026'],
                            ['param_name' => 'onboarding_time', 'example' => '10:30 AM'],
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
                'template_name' => 'onboarding_scheduled',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'onboarding_scheduled',
                'meta_template_id' => '1735748614154056',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Onboarding Scheduled',
                'header' => 'Onboarding Scheduled',
                'body_template' => <<<'BODY'
Hello {{name}},

Your CA Cloud Desk onboarding session has been scheduled successfully.

Date: {{onboarding_date}}
Time: {{onboarding_time}}
Join here: {{meeting_link}}

Our team will guide you through the initial setup and help you get started with CA Cloud Desk.

See you at the scheduled time.
BODY,
                'footer' => 'CaCloudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                    '{{onboarding_date}}' => 'scheduled_date',
                    '{{onboarding_time}}' => 'scheduled_time',
                    '{{meeting_link}}' => 'meeting_link',
                ],
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => [
                        'name',
                        'onboarding_date',
                        'onboarding_time',
                        'meeting_link',
                    ],
                    'meta_registered_body_parameters' => [
                        'name',
                        'onboarding_date',
                        'onboarding_time',
                        'meeting_link',
                    ],
                    'body_placeholder_parameters' => [
                        'name',
                        'onboarding_date',
                        'onboarding_time',
                        'meeting_link',
                    ],
                    'sample' => [
                        'name' => 'CA Ravi Kumar',
                        'onboarding_date' => '01-Sep-2026',
                        'onboarding_time' => '10:30 AM',
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
            ->where('template_name', 'onboarding_scheduled')
            ->where('language_code', 'en')
            ->delete();
    }
};
