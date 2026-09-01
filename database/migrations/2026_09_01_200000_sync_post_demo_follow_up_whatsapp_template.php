<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $metaStatusPayload = [
            'name' => 'post_demo_follow_up',
            'status' => 'APPROVED',
            'language' => 'en',
            'parameter_format' => 'NAMED',
            'components' => [
                [
                    'type' => 'HEADER',
                    'format' => 'TEXT',
                    'text' => 'Following Up on Your Demo',
                ],
                [
                    'type' => 'BODY',
                    'text' => "Hello {{name}},\n\nWe're following up regarding your recent CA Cloud Desk demo.\n\nIf you have any questions or would like to discuss the next steps, our team will be happy to assist you.",
                    'example' => [
                        'body_text_named_params' => [
                            ['param_name' => 'name', 'example' => 'CA Ravi Kumar'],
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
                        [
                            'type' => 'FLOW',
                            'text' => 'Share Your Interest',
                            'flow_action' => 'NAVIGATE',
                        ],
                    ],
                ],
            ],
        ];

        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'post_demo_follow_up',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'post_demo_follow_up',
                'meta_template_id' => '1722742815659867',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Post Demo Follow Up',
                'header' => 'Following Up on Your Demo',
                'body_template' => <<<'BODY'
Hello {{name}},

We're following up regarding your recent CA Cloud Desk demo.

If you have any questions or would like to discuss the next steps, our team will be happy to assist you.
BODY,
                'footer' => 'CaCloudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'MARKETING',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                ],
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => ['name'],
                    'meta_registered_body_parameters' => ['name'],
                    'body_placeholder_parameters' => ['name'],
                    'sample' => [
                        'name' => 'CA Ravi Kumar',
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
            ->where('template_name', 'post_demo_follow_up')
            ->where('language_code', 'en')
            ->delete();
    }
};
