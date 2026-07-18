<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** @var list<string> */
    private const APPROVED_TEMPLATES = [
        'expense_partnerjeyfg90rzl',
        'proforma_invoicel5ekuo0baa',
    ];

    public function up(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->whereNotIn('template_name', self::APPROVED_TEMPLATES)
            ->update([
                'is_active' => false,
                'publish_status' => 'disabled',
                'status' => 'archived',
            ]);

        $this->syncExpenseTemplate();
        $this->syncProformaTemplate();
    }

    public function down(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->whereIn('template_name', self::APPROVED_TEMPLATES)
            ->delete();
    }

    private function syncExpenseTemplate(): void
    {
        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'expense_partnerjeyfg90rzl',
                'language_code' => 'en_US',
            ],
            [
                'meta_api_name' => 'expense_partnerjeyfg90rzl',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Expense Recorded',
                'header' => 'Expense Recorded',
                'body_template' => <<<'BODY'
Dear Mr. {{CLIENT_NAME}},
Your expense entry of ₹{{AMOUNT}} dated {{EXPENSE_DATE}} under {{EXPENSE_CATEGORY}} has been recorded successfully.

Receipt:
Expense ID: {{EXPENSE_ID}}

Status:
Pending Manager Review.

— CA CloudDesk
BODY,
                'footer' => 'CA CloudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{CLIENT_NAME}}' => 'ca_name',
                    '{{AMOUNT}}' => 'amount',
                    '{{EXPENSE_DATE}}' => 'expense_date',
                    '{{EXPENSE_CATEGORY}}' => 'expense_category',
                    '{{EXPENSE_ID}}' => 'expense_id',
                ],
                'meta_components' => [
                    'body_parameters' => [
                        'ca_name',
                        'amount',
                        'expense_date',
                        'expense_category',
                        'expense_id',
                    ],
                    'sample' => [
                        'client_name' => 'Sample Client',
                        'amount' => '2,500',
                        'expense_date' => '10-July-2026',
                        'expense_category' => 'Travel',
                        'expense_id' => 'EXP-2026-0042',
                    ],
                ],
                'is_active' => true,
            ],
        );
    }

    private function syncProformaTemplate(): void
    {
        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'proforma_invoicel5ekuo0baa',
                'language_code' => 'en_US',
            ],
            [
                'meta_api_name' => 'proforma_invoicel5ekuo0baa',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Proforma Invoice Shared',
                'header' => 'Proforma Invoice Shared',
                'body_template' => <<<'BODY'
Dear {{CLIENT_NAME}},

We have prepared a Proforma Invoice for {{SERVICE_NAME}} dated {{INVOICE_DATE}}.

Estimated Amount:
₹{{AMOUNT}}

You can review the attached proforma invoice.

Kindly confirm so we may proceed.

— CA CloudDesk
BODY,
                'footer' => 'CA CloudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{CLIENT_NAME}}' => 'ca_name',
                    '{{SERVICE_NAME}}' => 'service_name',
                    '{{INVOICE_DATE}}' => 'invoice_date',
                    '{{AMOUNT}}' => 'amount',
                ],
                'meta_components' => [
                    'header' => [
                        'type' => 'document',
                        'document' => [
                            'filename' => 'proforma-invoice.pdf',
                        ],
                    ],
                    'body_parameters' => [
                        'ca_name',
                        'service_name',
                        'invoice_date',
                        'amount',
                    ],
                    'sample' => [
                        'client_name' => 'Sample Client',
                        'service_name' => 'GST Return Filing',
                        'invoice_date' => '10-July-2026',
                        'amount' => '15,000',
                    ],
                ],
                'is_active' => true,
            ],
        );
    }
};
