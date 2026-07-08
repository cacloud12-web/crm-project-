<?php

use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        EmailTemplate::query()->updateOrCreate(
            ['slug' => 'invoice-ready'],
            [
                'name' => 'Your Invoice is Ready',
                'description' => 'Notify client that their invoice is ready with amount and due date',
                'subject' => 'Your Invoice is Ready',
                'body' => "Dear {{CLIENT_NAME}},\n\nThank you for choosing our services.\n\nPlease find your invoice for {{SERVICE_NAME}} dated {{INVOICE_DATE}}.\nInvoice Amount: {{INVOICE_AMOUNT}}\nDue Date: {{DUE_DATE}}\n\nPlease find the invoice attached.\n\nFor any queries, feel free to reach out.",
                'variables' => [
                    '{{CLIENT_NAME}}',
                    '{CLIENT_NAME}',
                    '{{SERVICE_NAME}}',
                    '{SERVICE_NAME}',
                    '{{INVOICE_DATE}}',
                    '{INVOICE_DATE}',
                    '{{INVOICE_AMOUNT}}',
                    '{INVOICE_AMOUNT}',
                    '{{DUE_DATE}}',
                    '{DUE_DATE}',
                    '{SENDER_NAME}',
                ],
                'is_active' => true,
            ],
        );
    }

    public function down(): void
    {
        EmailTemplate::query()->where('slug', 'invoice-ready')->delete();
    }
};
