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
                'template_name' => 'welcome_after_purchase',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'welcome_after_purchase',
                'meta_template_id' => '1613055790494836',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Welcome After Purchase',
                'header' => 'Welcome to CA Cloud Desk',
                'body_template' => <<<'BODY'
Hello {{name}},

Welcome to CA Cloud Desk! Your subscription has been successfully activated.

Plan: {{plan_name}}
Valid Until: {{expiry_date}}

We're happy to have you with us. You can now start setting up your account and exploring CA Cloud Desk
BODY,
                'footer' => 'CaCakoudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                    '{{plan_name}}' => 'subscription_plan',
                    '{{expiry_date}}' => 'expiry_date',
                ],
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => ['name', 'plan_name', 'expiry_date'],
                    'meta_registered_body_parameters' => ['name', 'plan_name', 'expiry_date'],
                    'body_placeholder_parameters' => ['name', 'plan_name', 'expiry_date'],
                    'sample' => [
                        'name' => 'CA Ravi Kumar',
                        'plan_name' => 'Professional Plan',
                        'expiry_date' => '31-Aug-2027',
                    ],
                ],
                'is_active' => true,
            ],
        );
    }

    public function down(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'welcome_after_purchase')
            ->where('language_code', 'en')
            ->delete();
    }
};
