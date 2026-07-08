<?php

namespace Database\Seeders;

use App\Models\EmailSetting;
use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailSmtpSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = (array) config('email_smtp.env_defaults', []);

        EmailSetting::query()->updateOrCreate(
            ['is_default' => true],
            [
                'provider_name' => $defaults['provider_name'] ?? 'Cloud Desk',
                'smtp_host' => $defaults['smtp_host'] ?? 'smtpout.secureserver.net',
                'smtp_port' => (int) ($defaults['smtp_port'] ?? 465),
                'smtp_username' => $defaults['smtp_username'] ?? 'cacloud12@gmail.com',
                'smtp_password' => $defaults['smtp_password'] ?? null,
                'smtp_encryption' => $defaults['smtp_encryption'] ?? 'ssl',
                'from_email' => $defaults['from_email'] ?? 'cacloud12@gmail.com',
                'from_name' => $defaults['from_name'] ?? 'CA Cloud Desk',
                'reply_to_email' => $defaults['reply_to_email'] ?? 'cacloud12@gmail.com',
                'mode' => $defaults['mode'] ?? EmailSetting::MODE_LIVE,
                'is_active' => true,
                'is_default' => true,
            ],
        );

        EmailTemplate::query()->updateOrCreate(
            ['slug' => 'company-registration-docs'],
            [
                'name' => 'Documents Required for Company Registration',
                'description' => 'Request company registration documents from client',
                'subject' => 'Documents Required for Company Registration',
                'body' => "Dear {{CLIENT_NAME}},\n\nPlease send the required documents for company registration.\n\nIf you need any assistance, please contact us.\n\nRegards,\nLawSeva\nCA Cloud Desk",
                'variables' => [
                    '{{CLIENT_NAME}}',
                    '{CLIENT_NAME}',
                    '{SENDER_NAME}',
                ],
                'is_active' => true,
            ],
        );

        EmailTemplate::query()->updateOrCreate(
            ['slug' => 'audit-data-request'],
            [
                'name' => 'Audit Data Request',
                'description' => 'Request Sales & Purchase data from client',
                'subject' => 'Share your Sales & Purchase Data',
                'body' => "Dear {CLIENT_NAME},\n\nWe hope you are doing well.\n\nKindly share your Sales & Purchase data so that we can begin your accounting and compliance work.\n\nIf you have already shared the documents, please ignore this email.\n\nThank you.\n\nRegards,\n\n{CA_ORGANIZATION_NAME}\n{SENDER_NAME}",
                'variables' => [
                    '{CLIENT_NAME}',
                    '{CA_ORGANIZATION_NAME}',
                    '{SENDER_NAME}',
                    '{EMAIL}',
                    '{PHONE}',
                    '{CITY}',
                ],
                'is_active' => true,
            ],
        );

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
}
