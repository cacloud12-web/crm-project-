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
                'meta_components' => [
                    'parameter_format' => 'named',
                    'body_parameters' => ['name', 'time', 'link'],
                    'sample' => [
                        'name' => 'CA Ravi Kumar',
                        'time' => '10:30 PM',
                        'link' => 'https://meet.google.com/ouq-sxne-jwn',
                    ],
                ],
            ],
        );
    }

    public function down(): void
    {
        // Non-destructive patch migration.
    }
};
