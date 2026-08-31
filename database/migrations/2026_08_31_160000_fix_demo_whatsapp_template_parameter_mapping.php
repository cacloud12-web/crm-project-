<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->patchDemoScheduled();
        $this->patchDemoReminderOneHour();
        $this->patchDemoReminderOneDayBefore();
    }

    private function patchDemoScheduled(): void
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
                    'body_parameters' => ['name', 'date', 'time', 'link'],
                    'sample' => [
                        'name' => 'Sample Client',
                        'date' => '31-Aug-2026',
                        'time' => '10:30 AM',
                        'link' => 'https://meet.google.com/demo-sample',
                    ],
                ],
                'is_active' => true,
            ],
        );
    }

    private function patchDemoReminderOneHour(): void
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

Time: {{time}}
Join here using the Reschedule Demo button below.

See you soon.
CaCloudDesk
BODY,
                'footer' => 'CaCloudDesk',
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'publish_status' => 'active',
                'variable_map' => [
                    '{{name}}' => 'ca_name',
                    '{{time}}' => 'demo_time',
                    '{{link}}' => 'meeting_link',
                ],
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => ['name', 'time'],
                    'buttons' => [
                        [
                            'index' => '2',
                            'sub_type' => 'url',
                            'parameter_source' => 'link',
                            'parameter_name' => 'link',
                        ],
                    ],
                    'sample' => [
                        'name' => 'Sample Client',
                        'time' => '10:30 AM',
                        'link' => 'https://meet.google.com/demo-sample',
                    ],
                ],
                'is_active' => true,
            ],
        );
    }

    private function patchDemoReminderOneDayBefore(): void
    {
        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'demo_reminder_one_day_before',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'demo_reminder_one_day_before',
                'meta_status' => 'APPROVED',
                'meta_status_updated_at' => now(),
                'display_name' => 'Demo Reminder - One Day Before',
                'header' => 'Demo Reminder',
                'body_template' => <<<'BODY'
Hello {{name}},

This is a reminder that your CA Cloud Desk demo is scheduled for tomorrow.

Date: {{date}}
Time: {{time}}
Meeting Link: {{link}}

We look forward to meeting you.
CA CloudDesk
BODY,
                'footer' => 'CA CloudDesk',
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
                    'body_parameters' => ['name', 'date', 'time', 'link'],
                    'sample' => [
                        'name' => 'Sample Client',
                        'date' => '01-Sep-2026',
                        'time' => '10:30 AM',
                        'link' => 'https://meet.google.com/demo-sample',
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
