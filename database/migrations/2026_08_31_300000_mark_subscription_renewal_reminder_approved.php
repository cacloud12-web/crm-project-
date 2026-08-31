<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $template = MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'subscription_renewal_reminder')
            ->where('language_code', 'en')
            ->first();

        if (! $template) {
            return;
        }

        $meta = is_array($template->meta_components) ? $template->meta_components : [];
        unset($meta['buttons']);

        $template->update([
            'meta_components' => $meta,
            'meta_status' => 'APPROVED',
            'meta_status_updated_at' => now(),
            'status' => MessageTemplate::STATUS_APPROVED,
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'subscription_renewal_reminder')
            ->where('language_code', 'en')
            ->update([
                'meta_status' => 'PENDING',
                'status' => MessageTemplate::STATUS_PENDING,
                'meta_status_updated_at' => now(),
            ]);
    }
};
