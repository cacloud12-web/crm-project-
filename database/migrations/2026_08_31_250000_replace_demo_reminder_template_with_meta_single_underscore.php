<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'demo_reminder__1_hour')
            ->where('language_code', 'en')
            ->update([
                'is_active' => false,
                'publish_status' => 'disabled',
                'status' => MessageTemplate::STATUS_PENDING,
                'meta_status' => 'REPLACED',
                'meta_status_updated_at' => now(),
            ]);

        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'demo_reminder_1_hour',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'demo_reminder_1_hour',
                'meta_template_id' => '973816088450466',
                'meta_status' => 'PENDING',
                'meta_status_updated_at' => now(),
                'display_name' => 'Demo Reminder - 1 Hour',
                'header' => 'Demo Reminder',
                'body_template' => <<<'BODY'
Hello {{name}},

Just a reminder that your CA Cloud Desk demo will begin in approximately 1 hour.

Time: {{time}}
Join here: {{link}}

See you soon
BODY,
                'footer' => 'CA CloudDesk',
                'status' => MessageTemplate::STATUS_PENDING,
                'category' => 'MARKETING',
                'publish_status' => 'pending',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                    '{{time}}' => 'demo_time',
                    '{{link}}' => 'meeting_link',
                ],
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => ['name', 'time', 'link'],
                    'sample' => [
                        'name' => 'CA Ravi Kumar',
                        'time' => '10:30 PM',
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
            ->where('template_name', 'demo_reminder_1_hour')
            ->where('language_code', 'en')
            ->delete();

        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'demo_reminder__1_hour')
            ->where('language_code', 'en')
            ->update([
                'is_active' => true,
                'publish_status' => 'active',
                'status' => MessageTemplate::STATUS_APPROVED,
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
            ]);
    }
};
