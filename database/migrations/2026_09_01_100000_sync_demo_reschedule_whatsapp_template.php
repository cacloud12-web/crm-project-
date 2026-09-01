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
                'template_name' => 'demo_reschedule',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'demo_reschedule',
                'meta_template_id' => '1051800947557577',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Demo Reschedule',
                'header' => 'Demo Reschedule',
                'body_template' => <<<'BODY'
Hello {{name}},

Your CA Cloud Desk demo has been rescheduled as requested.

New Date: {{date}}
New Time: {{time}}
Meeting Link: {{link}}

We look forward to connecting with you
BODY,
                'footer' => 'CaCloudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'MARKETING',
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
                        'name' => 'CA Ravi Kumar',
                        'date' => '01-Sep-2026',
                        'time' => '10:30 AM',
                        'link' => 'https://meet.google.com/ouq-sxne-jwn',
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
            ->where('template_name', 'demo_reschedule')
            ->where('language_code', 'en')
            ->delete();
    }
};
