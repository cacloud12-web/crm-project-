<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $payload = [
            'meta_api_name' => 'subscription_renewal_reminder',
            'meta_status' => 'APPROVED',
            'meta_status_updated_at' => now(),
            'display_name' => 'Subscription Renewal Reminder',
            'header' => 'Subscription Renewal Reminder',
            'body_template' => <<<'BODY'
Hello {{name}},

This is a reminder that your CA Cloud Desk subscription is due for renewal on {{Renewal Due Date}}.

Plan: {{Subscription Plan}}
Renewal Amount: {{Renewal Amount}}

Please renew your subscription to continue uninterrupted access to your account.
BODY,
            'footer' => 'CaCloudDesk',
            'status' => MessageTemplate::STATUS_APPROVED,
            'category' => 'UTILITY',
            'publish_status' => 'active',
            'variable_map' => [
                '{{name}}' => 'ca_name',
                '{{Renewal Due Date}}' => 'renewal_due_date',
                '{{Subscription Plan}}' => 'subscription_plan',
                '{{Renewal Amount}}' => 'renewal_amount',
            ],
            'meta_components' => [
                'parameter_format' => 'named',
                'body_parameters' => [
                    'name',
                    'Renewal Due Date',
                    'Subscription Plan',
                    'Renewal Amount',
                ],
                'sample' => [
                    'name' => 'CA Ravi Kumar',
                    'Renewal Due Date' => '15-Sep-2026',
                    'Subscription Plan' => 'Professional Plan',
                    'Renewal Amount' => '15,000',
                ],
                'flow_buttons' => [
                    [
                        'index' => '2',
                        'flow_action' => 'navigate',
                        'navigate_screen' => 'QUESTION_ONE',
                    ],
                ],
            ],
            'is_active' => true,
        ];

        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'subscription_renewal_reminder')
            ->each(function (MessageTemplate $template) use ($payload): void {
                $template->update($payload);
            });

        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'subscription_renewal_reminder',
                'language_code' => 'en',
            ],
            $payload,
        );
    }

    public function down(): void
    {
        // Non-destructive patch migration.
    }
};
