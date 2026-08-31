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
        $meta['parameter_format'] = 'named';
        $meta['body_parameters'] = [
            'name',
            'Renewal Due Date',
            'Subscription Plan',
            'Renewal Amount',
        ];
        $meta['sample'] = [
            'name' => 'CA Ravi Kumar',
            'Renewal Due Date' => '15-Sep-2026',
            'Subscription Plan' => 'Professional Plan',
            'Renewal Amount' => '15,000',
        ];

        $template->update([
            'body_template' => <<<'BODY'
Hello {{name}},

This is a reminder that your CA Cloud Desk subscription is due for renewal on {{Renewal Due Date}}.

Plan: {{Subscription Plan}}
Renewal Amount: {{Renewal Amount}}

Please renew your subscription to continue uninterrupted access to your account.
BODY,
            'meta_components' => $meta,
            'meta_status' => 'APPROVED',
            'status' => MessageTemplate::STATUS_APPROVED,
            'is_active' => true,
            'meta_status_updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Non-destructive patch migration.
    }
};
