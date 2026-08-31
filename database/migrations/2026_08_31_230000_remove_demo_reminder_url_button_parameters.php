<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $template = MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'demo_reminder__1_hour')
            ->where('language_code', 'en')
            ->first();

        if (! $template) {
            return;
        }

        $meta = is_array($template->meta_components) ? $template->meta_components : [];
        unset($meta['buttons']);

        $template->update([
            'meta_components' => $meta,
        ]);
    }

    public function down(): void
    {
        // Non-destructive patch migration.
    }
};
