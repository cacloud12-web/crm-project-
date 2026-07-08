<?php

namespace App\Console\Commands;

use App\Models\MessageTemplate;
use App\Services\WhatsApp\WhatsAppCloudMappingService;
use App\Services\WhatsApp\WhatsAppDispatchService;
use App\Services\WhatsApp\WhatsAppSettingsService;
use Illuminate\Console\Command;

class WhatsAppSetMetaTemplateNameCommand extends Command
{
    protected $signature = 'whatsapp:set-meta-template-name
                            {meta_name : Exact template name from Meta WhatsApp Manager}
                            {--crm-template=company_registration_docs : CRM template_name slug}
                            {--language=en : Meta language code}
                            {--probe : Send a dry probe to Meta without saving}';

    protected $description = 'Map a CRM WhatsApp template to the exact Meta-approved template name';

    public function handle(
        WhatsAppSettingsService $settingsService,
        WhatsAppCloudMappingService $mappingService,
        WhatsAppDispatchService $dispatchService,
    ): int {
        $crmName = (string) $this->option('crm-template');
        $metaName = strtolower((string) $this->argument('meta_name'));
        $language = (string) $this->option('language');

        if (! preg_match('/^[a-z0-9_]+$/', $metaName)) {
            $this->error('Meta template names use lowercase letters, numbers, and underscores only.');

            return self::FAILURE;
        }

        $template = MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', $crmName)
            ->where('language_code', $language)
            ->first();

        if (! $template) {
            $this->error("CRM template {$crmName} ({$language}) not found.");

            return self::FAILURE;
        }

        if ($this->option('probe')) {
            $settings = $settingsService->current();
            $probeTemplate = clone $template;
            $probeTemplate->meta_api_name = $metaName;
            $payload = $mappingService->buildTestTemplatePayload(
                $probeTemplate,
                (string) ($settings->test_mobile_number ?? '919876543210'),
                $settings,
            );
            $result = $dispatchService->send($settings, $payload);
            $this->line(json_encode($result['provider_response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info($result['success'] ? 'Meta accepted this template name.' : ($result['error_message'] ?? 'Probe failed.'));

            return $result['success'] ? self::SUCCESS : self::FAILURE;
        }

        $template->update(['meta_api_name' => $metaName]);
        $this->info("Mapped CRM template {$crmName} → Meta name {$metaName} ({$language})");
        $this->line('Body: '.$template->body_template);
        $this->comment('Run: php artisan communication:test-report --live');

        return self::SUCCESS;
    }
}
