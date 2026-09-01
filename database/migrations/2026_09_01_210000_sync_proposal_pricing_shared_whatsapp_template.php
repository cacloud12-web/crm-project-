<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $metaStatusPayload = [
            'name' => 'proposal_pricing_shared',
            'status' => 'APPROVED',
            'language' => 'en',
            'parameter_format' => 'NAMED',
            'components' => [
                [
                    'type' => 'HEADER',
                    'format' => 'TEXT',
                    'text' => 'Proposal & Pricing Details',
                ],
                [
                    'type' => 'BODY',
                    'text' => "Hello {{name}},\n\nThank you for your interest in CA Cloud Desk. We have shared the proposal and pricing details for your review.\n\nPlease review them at your convenience. If you have any questions or would like to discuss the proposal, our team will be happy to assist you.",
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
                            'text' => 'Proposal Response',
                            'flow_action' => 'NAVIGATE',
                        ],
                    ],
                ],
            ],
        ];

        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'proposal_pricing_shared',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'proposal_pricing_shared',
                'meta_template_id' => '2124625535106564',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Proposal Pricing Shared',
                'header' => 'Proposal & Pricing Details',
                'body_template' => <<<'BODY'
Hello {{name}},

Thank you for your interest in CA Cloud Desk. We have shared the proposal and pricing details for your review.

Please review them at your convenience. If you have any questions or would like to discuss the proposal, our team will be happy to assist you.
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
            ->where('template_name', 'proposal_pricing_shared')
            ->where('language_code', 'en')
            ->delete();
    }
};
