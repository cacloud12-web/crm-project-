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
                'template_name' => 'demo_reminder__1_hour',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'demo_reminder__1_hour',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Demo Reminder - 1 Hour',
                'header' => 'Demo Reminder',
                'body_template' => <<<'BODY'
Hello {{name}},

Just a reminder that your CA Cloud Desk demo will begin in approximately 1 hour.

Time: {{10:30}}
Join here: {{link}}

See you soon.
CaCloudDesk
BODY,
                'footer' => 'CaCloudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'MARKETING',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                    '{{link}}' => 'meeting_link',
                ],
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => ['name', 'link'],
                    'buttons' => [
                        [
                            'index' => '0',
                            'sub_type' => 'url',
                            'parameter_source' => 'link',
                            'url_base' => 'https://meet.google.com/',
                            'sample_suffix' => 'ouq-sxne-jwn',
                        ],
                    ],
                    'sample' => [
                        'name' => 'CA Ravi Kumar',
                        'link' => 'https://meet.google.com/ouq-sxne-jwn',
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
