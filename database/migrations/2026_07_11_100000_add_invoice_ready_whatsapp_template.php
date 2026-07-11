<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const TEMPLATE_NAME = 'invoice_ready';

    private const BODY = <<<'BODY'
Dear {{1}},
Thank you for choosing our services.
Please find your invoice for *{{2}}* dated *{{3}}*.
Invoice Amount: *{{4}}*
Due Date: *{{5}}*

Please find the invoice attached.

For any queries, feel free to reach out.
BODY;

    public function up(): void
    {
        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => self::TEMPLATE_NAME,
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => self::TEMPLATE_NAME,
                'meta_status' => 'APPROVED',
                'display_name' => 'Your Invoice is Ready',
                'header' => '📄 Your Invoice is Ready',
                'body_template' => self::BODY,
                'footer' => null,
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{1}}' => 'ca_name',
                    '{{2}}' => 'service_name',
                    '{{3}}' => 'invoice_date',
                    '{{4}}' => 'invoice_amount',
                    '{{5}}' => 'due_date',
                ],
                'meta_components' => [
                    'header' => [
                        'type' => 'document',
                        'document' => [
                            // Override via WHATSAPP_INVOICE_DOCUMENT_URL in .env / config
                            'filename' => 'invoice.pdf',
                        ],
                    ],
                    'body_parameters' => [
                        'ca_name',
                        'service_name',
                        'invoice_date',
                        'invoice_amount',
                        'due_date',
                    ],
                    'sample' => [
                        'client_name' => 'Prayag',
                        'service_name' => 'GST Return',
                        'invoice_date' => '24-June-2025',
                        'invoice_amount' => '₹10,150',
                        'due_date' => '28-June-2025',
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
            ->where('template_name', self::TEMPLATE_NAME)
            ->where('language_code', 'en')
            ->delete();
    }
};
