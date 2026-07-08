<?php

use App\Models\EmailTemplate;
use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'company_registration_docs',
                'language_code' => 'en',
            ],
            [
                'display_name' => 'Company Registration Docs',
                'body_template' => "Hi {{1}},\n\nPlease send the required documents for company registration.\n\nNeed help? Reach us.\n\nRegards,\n{{2}}",
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'variable_map' => [
                    '{{1}}' => 'ca_name',
                    '{{2}}' => 'static:LawSeva',
                ],
                'meta_components' => [
                    'body_parameters' => [
                        ['position' => 1, 'label' => 'Client Name', 'source' => 'ca_name'],
                        ['position' => 2, 'label' => 'Sender', 'source' => 'static:LawSeva'],
                    ],
                ],
                'is_active' => true,
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
    }

    public function down(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'company_registration_docs')
            ->where('language_code', 'en')
            ->delete();

        EmailTemplate::query()
            ->where('slug', 'company-registration-docs')
            ->delete();
    }
};
