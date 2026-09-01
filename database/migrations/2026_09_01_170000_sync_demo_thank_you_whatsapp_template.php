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
                'template_name' => 'demo_thank_you',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'demo_thank_you',
                'meta_template_id' => '1381893824026531',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Demo Thank You',
                'header' => 'Thank You for Your Time',
                'body_template' => <<<'BODY'
Hello {{name}},

Thank you for attending the CA Cloud Desk demo. It was a pleasure connecting with you.

We hope the session was helpful and look forward to assisting you further
BODY,
                'footer' => 'CaClouudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'MARKETING',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                ],
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => ['name'],
                    'meta_registered_body_parameters' => ['name'],
                    'body_placeholder_parameters' => ['name'],
                    'sample' => [
                        'name' => 'CA Ravi Kumar',
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
            ->where('template_name', 'demo_thank_you')
            ->where('language_code', 'en')
            ->delete();
    }
};
