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
                'template_name' => 'payment_received',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'payment_received',
                'meta_template_id' => '28590411563877306',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Payment Received',
                'header' => 'Payment Received',
                'body_template' => <<<'BODY'
Hello {{name}},

Thank you. We have successfully received your payment of {{amount}} for CA Cloud Desk.

Payment Date: {{payment_date}}

Your payment has been recorded successfully.
BODY,
                'footer' => 'CaCloudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'MARKETING',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                    '{{amount}}' => 'amount',
                    '{{payment_date}}' => 'payment_date',
                ],
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => ['name', 'amount', 'payment_date'],
                    'meta_registered_body_parameters' => ['name', 'amount', 'payment_date'],
                    'body_placeholder_parameters' => ['name', 'amount', 'payment_date'],
                    'sample' => [
                        'name' => 'CA Ravi Kumar',
                        'amount' => '15,000',
                        'payment_date' => '31-Aug-2026',
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
            ->where('template_name', 'payment_received')
            ->where('language_code', 'en')
            ->update([
                'status' => MessageTemplate::STATUS_PENDING,
                'meta_status' => 'PENDING',
            ]);
    }
};
