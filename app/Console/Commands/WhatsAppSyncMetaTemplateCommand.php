<?php

namespace App\Console\Commands;

use App\Models\MessageTemplate;
use App\Services\WhatsApp\WhatsAppMetaTemplateService;
use App\Services\WhatsApp\WhatsAppSettingsService;
use Illuminate\Console\Command;

class WhatsAppSyncMetaTemplateCommand extends Command
{
    protected $signature = 'whatsapp:sync-meta-template
                            {name? : CRM or Meta template name (e.g. demo_reminder_1_hour)}
                            {--all : Sync all active approved WhatsApp templates}';

    protected $description = 'Fetch approved template structure from Meta and update CRM parameter mapping';

    public function handle(
        WhatsAppSettingsService $settingsService,
        WhatsAppMetaTemplateService $metaTemplateService,
    ): int {
        $settings = $settingsService->current();
        if (! $settings->hasAccessToken()) {
            $this->error('WhatsApp access token is not configured.');

            return self::FAILURE;
        }

        $names = [];
        if ($this->option('all')) {
            $names = MessageTemplate::query()
                ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
                ->where('is_active', true)
                ->pluck('template_name')
                ->all();
        } elseif ($this->argument('name')) {
            $names = [(string) $this->argument('name')];
        } else {
            $this->error('Provide a template name or use --all.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($names as $name) {
            $template = MessageTemplate::query()
                ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
                ->where(function ($query) use ($name) {
                    $query->where('template_name', $name)
                        ->orWhere('meta_api_name', $name);
                })
                ->orderByDesc('is_active')
                ->first();

            if (! $template) {
                $this->warn("Template not found in CRM: {$name}");
                $failed++;

                continue;
            }

            try {
                $synced = $metaTemplateService->syncTemplateStructure($template, $settings);
                $meta = is_array($synced->meta_components) ? $synced->meta_components : [];
                $this->info("Synced {$synced->template_name} · language={$synced->language_code}");
                $this->line('  format: '.($meta['parameter_format'] ?? 'unknown'));
                $this->line('  body: '.json_encode($meta['body_parameters'] ?? []));
                if (! empty($meta['buttons'])) {
                    $this->line('  buttons: '.json_encode($meta['buttons']));
                }
            } catch (\Throwable $exception) {
                $this->error("Failed {$name}: ".$exception->getMessage());
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
