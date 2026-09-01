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
                'template_name' => 'subscription_renewal_reminder',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'subscription_renewal_reminder',
                'meta_template_id' => '1088763557462839',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Subscription Renewal Reminder',
                'header' => 'Subscription Renewal Reminder',
                'body_template' => <<<'BODY'
Hello {{name}},

This is a reminder that your CA Cloud Desk subscription is due for renewal on {{renewal_date}}.

Plan: {{subscription_plan}}
Renewal Amount: {{renewal_amount}}

Please renew your subscription to continue uninterrupted access to your account.
BODY,
                'footer' => 'CaCloudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                    '{{renewal_date}}' => 'renewal_due_date',
                    '{{subscription_plan}}' => 'subscription_plan',
                    '{{renewal_amount}}' => 'renewal_amount',
                ],
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => [
                        'name',
                        'renewal_date',
                        'subscription_plan',
                        'renewal_amount',
                    ],
                    'meta_registered_body_parameters' => [
                        'name',
                        'renewal_date',
                        'subscription_plan',
                        'renewal_amount',
                    ],
                    'body_placeholder_parameters' => [
                        'name',
                        'renewal_date',
                        'subscription_plan',
                        'renewal_amount',
                    ],
                    'sample' => [
                        'name' => 'CA Ravi Kumar',
                        'renewal_date' => '15-Sep-2026',
                        'subscription_plan' => 'Professional Plan',
                        'renewal_amount' => '15,000',
                    ],
                ],
                'is_active' => true,
            ],
        );
    }

    public function down(): void
    {
        // Non-destructive patch migration.
    }
};
