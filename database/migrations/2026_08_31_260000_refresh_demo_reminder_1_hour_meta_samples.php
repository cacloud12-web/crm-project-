<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $template = MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'demo_reminder_1_hour')
            ->where('language_code', 'en')
            ->first();

        if (! $template) {
            return;
        }

        $meta = is_array($template->meta_components) ? $template->meta_components : [];
        unset($meta['buttons']);
        $meta['parameter_format'] = 'named';
        $meta['body_parameters'] = ['name', 'time', 'link'];
        $meta['sample'] = [
            'name' => 'CA Ravi Kumar',
            'time' => '10:30 PM',
            'link' => 'https://meet.google.com/ouq-sxne-jwn',
        ];

        $template->update([
            'meta_components' => $meta,
            'meta_status' => 'APPROVED',
            'status' => MessageTemplate::STATUS_APPROVED,
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        // Non-destructive patch migration.
    }
};
