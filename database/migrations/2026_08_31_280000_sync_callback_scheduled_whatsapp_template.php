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
                'template_name' => 'callback_scheduled',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'callback_scheduled',
                'meta_template_id' => '906276382185545',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Callback Scheduled',
                'header' => 'Callback Scheduled',
                'body_template' => <<<'BODY'
Hello {{name}},

As discussed, our follow-up call has been scheduled.

Date: {{date}}
Time: {{time}}

Our representative, will connect with you at the scheduled time.
BODY,
                'footer' => 'CaCloudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'MARKETING',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                    '{{date}}' => 'demo_date',
                    '{{time}}' => 'demo_time',
                ],
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => ['name', 'date', 'time'],
                    'sample' => [
                        'name' => 'CA Ravi Kumar',
                        'date' => '01-Sep-2026',
                        'time' => '10:30 AM',
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
            ->where('template_name', 'callback_scheduled')
            ->where('language_code', 'en')
            ->delete();
    }
};
