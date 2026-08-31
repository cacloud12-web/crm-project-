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
                'template_name' => 'demo_scheduled',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'demo_scheduled',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Demo Scheduled - CA CloudDesk',
                'header' => 'Demo Scheduled - CA CloudDesk',
                'body_template' => <<<'BODY'
Hello {{name}},

Your CA Cloud Desk demo has been successfully scheduled.

Date: {{date}}
Time: {{time}}
Meeting Link: {{link}}

We look forward to connecting with you.

https://caclouddesk.com/
Team CA Cloud Desk
BODY,
                'footer' => 'Team CA Cloud Desk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                    '{{date}}' => 'demo_date',
                    '{{time}}' => 'demo_time',
                    '{{link}}' => 'meeting_link',
                ],
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => [
                        'name',
                        'date',
                        'time',
                        'link',
                    ],
                    'sample' => [
                        'name' => 'Sample Client',
                        'date' => '31-Aug-2026',
                        'time' => '07:34 AM',
                        'link' => 'https://meet.google.com/demo-sample',
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
            ->where('template_name', 'demo_scheduled')
            ->where('language_code', 'en')
            ->delete();
    }
};
