<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * @return list<string>
     */
    private function extractPlaceholderNames(string $bodyTemplate): array
    {
        if ($bodyTemplate === '' || ! preg_match_all('/\{\{([^}]+)\}\}/', $bodyTemplate, $matches)) {
            return [];
        }

        return array_values(array_map(static fn (string $name) => trim($name), $matches[1]));
    }

    public function up(): void
    {
        $registeredOverrides = [
            'subscription_renewal_reminder' => ['name'],
            'demo_reminder_1_hour' => ['name', 'time', 'link'],
            'demo_reminder_one_day_before' => ['name', 'date', 'time', 'link'],
            'demo_scheduled' => ['name', 'date', 'time', 'link'],
            'demo_reschedule' => ['name', 'date', 'time', 'link'],
            'callback_scheduled' => ['name', 'date', 'time'],
            'payment_received' => ['name', 'amount', 'payment_date'],
        ];

        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->each(function (MessageTemplate $template) use ($registeredOverrides): void {
                $meta = is_array($template->meta_components) ? $template->meta_components : [];
                unset($meta['buttons'], $meta['flow_buttons']);

                $placeholders = $this->extractPlaceholderNames((string) $template->body_template);
                if ($placeholders !== []) {
                    $meta['body_placeholder_parameters'] = $placeholders;
                }

                $override = $registeredOverrides[$template->template_name] ?? null;
                if (is_array($override) && $override !== []) {
                    $meta['meta_registered_body_parameters'] = $override;
                }

                $template->update(['meta_components' => $meta]);
            });
    }

    public function down(): void
    {
        // Non-destructive patch migration.
    }
};
