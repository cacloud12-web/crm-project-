<?php

namespace App\Console\Commands;

use App\Models\MessageTemplate;
use App\Services\WhatsApp\WhatsAppCloudMappingService;
use App\Services\WhatsApp\WhatsAppMetaTemplateService;
use App\Services\WhatsApp\WhatsAppSettingsService;
use Illuminate\Console\Command;

class WhatsAppDebugTemplatePayloadCommand extends Command
{
    protected $signature = 'whatsapp:debug-template-payload
                            {name=demo_reminder_1_hour : CRM or Meta template name}
                            {--language=en : Template language code}';

    protected $description = 'Build and print the Meta send payload for a WhatsApp template (no message sent)';

    public function handle(
        WhatsAppSettingsService $settingsService,
        WhatsAppCloudMappingService $mappingService,
        WhatsAppMetaTemplateService $metaTemplateService,
    ): int {
        $name = (string) $this->argument('name');
        $language = (string) $this->option('language');

        $template = MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where(function ($query) use ($name) {
                $query->where('template_name', $name)
                    ->orWhere('meta_api_name', $name);
            })
            ->where('language_code', $language)
            ->first();

        if (! $template) {
            $this->error("Template {$name} ({$language}) not found in CRM.");

            return self::FAILURE;
        }

        $settings = $settingsService->current();
        $payload = $mappingService->buildTestTemplatePayload(
            $template,
            (string) ($settings->test_mobile_number ?? '919999999999'),
            $settings,
        );

        $this->info('CRM template record:');
        $this->line(json_encode([
            'id' => $template->id,
            'template_name' => $template->template_name,
            'language_code' => $template->language_code,
            'meta_api_name' => $template->metaApiTemplateName(),
            'send_language_code' => $template->metaApiLanguageCode(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('CRM template mapping:');
        $this->line(json_encode($template->meta_components, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($settings->hasAccessToken()) {
            $definition = $metaTemplateService->fetchTemplateDefinition(
                $template->metaApiTemplateName(),
                $settings,
                $language,
            );
            $this->info('Meta approved template (Graph API):');
            $this->line(json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->warn('Access token not configured — skipping Meta fetch.');
        }

        $sendTemplate = $payload['request_body']['template'] ?? [];
        $sendBodyParams = $sendTemplate['components'][0]['parameters'] ?? [];
        $sendParamNames = array_values(array_map(
            static fn (array $param): string => (string) ($param['parameter_name'] ?? ''),
            is_array($sendBodyParams) ? $sendBodyParams : [],
        ));

        $this->info('Send payload (request_body.template):');
        $this->line(json_encode($sendTemplate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($settings->hasAccessToken() && isset($definition) && is_array($definition)) {
            $parsed = $metaTemplateService->parseMetaTemplateStructure($definition);
            $expected = is_array($parsed['body_parameters'] ?? null) ? $parsed['body_parameters'] : [];
            $this->info('Parameter check:');
            $this->line(json_encode([
                'expected_from_meta_body' => $expected,
                'send_parameter_names' => $sendParamNames,
                'expected_count' => count($expected),
                'send_count' => count($sendParamNames),
                'names_match' => $expected === $sendParamNames,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ($expected !== $sendParamNames) {
                $this->warn('Mismatch: Meta body placeholders and send parameter_name values must match exactly (including spaces).');
            }
        }

        return self::SUCCESS;
    }
}
