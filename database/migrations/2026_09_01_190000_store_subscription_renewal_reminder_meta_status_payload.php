<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $metaStatusPayload = [
            'name' => 'subscription_renewal_reminder',
            'status' => 'APPROVED',
            'language' => 'en',
            'parameter_format' => 'NAMED',
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => 'Hello {{name}}, This is a reminder that your CA Cloud Desk subscription is due for renewal on {{renewal_date}}. Plan: {{subscription_plan}} Renewal Amount: {{renewal_amount}} Please renew your subscription to continue uninterrupted access to your account.',
                    'example' => [
                        'body_text_named_params' => [
                            ['param_name' => 'name', 'example' => 'CA Ravi Kumar'],
                            ['param_name' => 'renewal_date', 'example' => '15-Sep-2026'],
                            ['param_name' => 'subscription_plan', 'example' => 'Professional Plan'],
                            ['param_name' => 'renewal_amount', 'example' => '15,000'],
                        ],
                    ],
                ],
                [
                    'type' => 'BUTTONS',
                    'buttons' => [
                        [
                            'type' => 'URL',
                            'text' => 'Visit website',
                            'url' => 'https://caclouddesk.com',
                        ],
                        [
                            'type' => 'PHONE_NUMBER',
                            'text' => 'Call phone number',
                            'phone_number' => '+919876543210',
                        ],
                        [
                            'type' => 'FLOW',
                            'text' => 'Renewal Options',
                            'flow_action' => 'NAVIGATE',
                        ],
                    ],
                ],
            ],
        ];

        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'subscription_renewal_reminder')
            ->each(function (MessageTemplate $template) use ($metaStatusPayload): void {
                $template->update([
                    'meta_status_payload' => $metaStatusPayload,
                ]);
            });
    }

    public function down(): void
    {
        // Non-destructive patch migration.
    }
};
