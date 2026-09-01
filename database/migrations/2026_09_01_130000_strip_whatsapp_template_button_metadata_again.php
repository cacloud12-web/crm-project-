<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->each(function (MessageTemplate $template): void {
                $meta = is_array($template->meta_components) ? $template->meta_components : [];
                unset($meta['buttons'], $meta['flow_buttons']);

                if ($meta !== ($template->meta_components ?? [])) {
                    $template->update(['meta_components' => $meta]);
                }
            });
    }

    public function down(): void
    {
        // Non-destructive patch migration.
    }
};
