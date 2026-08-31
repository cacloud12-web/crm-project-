<?php

namespace App\Services\WhatsApp;

use App\Models\MessageTemplate;
use App\Models\WhatsAppSetting;
use App\Services\Activity\ActivityLogService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WhatsAppMetaTemplateService
{
    public function __construct(
        private readonly WhatsAppSettingsService $settingsService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildCreatePayload(MessageTemplate $template): array
    {
        $name = $template->metaApiTemplateName();
        $language = $this->normalizeLanguageCode($template->language_code);
        $category = strtoupper((string) ($template->category ?: 'UTILITY'));

        if (! in_array($category, ['MARKETING', 'UTILITY', 'AUTHENTICATION'], true)) {
            $category = 'UTILITY';
        }

        $bodyText = trim((string) $template->body_template);
        preg_match_all('/\{\{[^}]+\}\}/', $bodyText, $matches);
        $placeholders = array_values(array_unique($matches[0] ?? []));

        $metaBody = $bodyText;
        $examples = [];
        $index = 1;

        foreach ($placeholders as $placeholder) {
            $metaBody = str_replace($placeholder, '{{'.$index.'}}', $metaBody);
            $examples[] = $this->exampleValueForPlaceholder($placeholder);
            $index++;
        }

        $bodyComponent = [
            'type' => 'BODY',
            'text' => $metaBody,
        ];

        if ($examples !== []) {
            $bodyComponent['example'] = ['body_text' => [$examples]];
        }

        return [
            'name' => $name,
            'language' => $language,
            'category' => $category,
            'components' => [$bodyComponent],
        ];
    }

    /**
     * Submit template to Meta for review (POST /{WABA_ID}/message_templates).
     *
     * @return array{success: bool, message: string, provider_response: array<string, mixed>, template: MessageTemplate}
     */
    public function createOnMeta(MessageTemplate $template, ?WhatsAppSetting $settings = null): array
    {
        $settings ??= $this->settingsService->current();
        $this->settingsService->assertReadyForLiveDispatch($settings);

        if (! filled($settings->business_account_id)) {
            throw ValidationException::withMessages([
                'business_account_id' => ['Business Account ID is required to create Meta templates.'],
            ]);
        }

        $payload = $this->buildCreatePayload($template);
        $endpoint = $this->messageTemplatesEndpoint($settings);

        Log::info('whatsapp.meta_template.create.request', [
            'endpoint' => $endpoint,
            'template_name' => $payload['name'],
            'payload' => $payload,
        ]);

        try {
            $response = Http::timeout(30)
                ->withToken((string) $settings->access_token)
                ->acceptJson()
                ->post($endpoint, $payload);

            $providerResponse = $response->json();
            if (! is_array($providerResponse)) {
                $providerResponse = [
                    'http_status' => $response->status(),
                    'raw' => $response->body(),
                ];
            } else {
                $providerResponse['http_status'] = $response->status();
            }
            $providerResponse['request'] = $payload;
            $providerResponse['endpoint'] = $endpoint;
        } catch (\Throwable $exception) {
            Log::error('whatsapp.meta_template.create.exception', [
                'message' => $exception->getMessage(),
                'template' => $template->template_name,
            ]);

            throw ValidationException::withMessages([
                'template' => ['Meta template creation failed: '.$exception->getMessage()],
            ]);
        }

        Log::info('whatsapp.meta_template.create.response', [
            'http_status' => $response->status(),
            'provider_response' => $providerResponse,
        ]);

        if (! $response->successful() || isset($providerResponse['error'])) {
            $message = $this->extractErrorMessage($providerResponse);

            throw ValidationException::withMessages([
                'template' => [$message],
            ]);
        }

        $metaTemplateId = isset($providerResponse['id']) ? (string) $providerResponse['id'] : null;
        $metaStatus = strtoupper((string) ($providerResponse['status'] ?? 'PENDING'));
        $category = $providerResponse['category'] ?? $template->category;

        $template->update([
            'meta_template_id' => $metaTemplateId,
            'meta_api_name' => $payload['name'],
            'meta_status' => $metaStatus,
            'meta_rejection_reason' => null,
            'meta_status_payload' => $providerResponse,
            'meta_submitted_at' => now(),
            'meta_status_updated_at' => now(),
            'category' => $category,
            'status' => $this->mapMetaEventToCrmStatus($metaStatus),
            'is_active' => in_array($metaStatus, ['APPROVED', 'REINSTATED'], true),
        ]);

        $this->activityLogService->log(
            'WHATSAPP_SETTINGS',
            'Meta Template Submitted',
            (string) $template->id,
            $payload['name'].' · '.$metaStatus,
        );

        return [
            'success' => true,
            'message' => 'Template submitted to Meta. Status: '.$metaStatus,
            'provider_response' => $providerResponse,
            'template' => $template->fresh(),
        ];
    }

    /**
     * Handle message_template_status_update webhook payload value.
     *
     * @param  array<string, mixed>  $value
     */
    public function applyTemplateStatusWebhook(array $value): ?MessageTemplate
    {
        $event = strtoupper((string) ($value['event'] ?? ''));
        $metaName = (string) ($value['message_template_name'] ?? '');
        $language = $this->normalizeLanguageCode((string) ($value['message_template_language'] ?? 'en'));
        $metaTemplateId = isset($value['message_template_id']) ? (string) $value['message_template_id'] : null;

        if ($metaName === '' || $event === '') {
            return null;
        }

        $template = MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where(function ($query) use ($metaName) {
                $query->where('meta_api_name', $metaName)
                    ->orWhere('template_name', $metaName);
            })
            ->orderByDesc('id')
            ->get()
            ->first(fn (MessageTemplate $row) => $this->languageCodesMatch((string) $row->language_code, $language));

        if (! $template) {
            Log::warning('whatsapp.webhook.template_status.unknown_template', [
                'message_template_name' => $metaName,
                'language' => $language,
                'event' => $event,
            ]);

            return null;
        }

        $rejectionReason = null;
        if ($event === 'REJECTED') {
            $rejectionReason = $value['rejection_info']['reason']
                ?? $value['reason']
                ?? 'Template rejected by Meta';
        } elseif (isset($value['reason']) && $value['reason'] !== 'NONE') {
            $rejectionReason = (string) $value['reason'];
        }

        $crmStatus = $this->mapMetaEventToCrmStatus($event);
        $isDispatchable = in_array($event, ['APPROVED', 'REINSTATED', 'UNARCHIVED'], true);

        $template->update([
            'meta_template_id' => $metaTemplateId ?: $template->meta_template_id,
            'meta_api_name' => $metaName,
            'meta_status' => $event,
            'meta_rejection_reason' => $rejectionReason,
            'meta_status_payload' => $value,
            'meta_status_updated_at' => now(),
            'status' => $crmStatus,
            'is_active' => $isDispatchable,
            'category' => $value['message_template_category'] ?? $template->category,
        ]);

        $this->activityLogService->log(
            'WHATSAPP_SETTINGS',
            'Meta Template Status',
            (string) $template->id,
            $metaName.' · '.$event.($rejectionReason ? ' · '.$rejectionReason : ''),
        );

        Log::info('whatsapp.webhook.template_status.applied', [
            'template_id' => $template->id,
            'meta_name' => $metaName,
            'event' => $event,
        ]);

        return $template->fresh();
    }

    public function messageTemplatesEndpoint(?WhatsAppSetting $settings = null): string
    {
        $settings ??= $this->settingsService->current();
        $base = rtrim((string) config('whatsapp_cloud.graph_base_url'), '/');
        $version = $settings->api_version;
        $wabaId = $settings->business_account_id;

        return "{$base}/{$version}/{$wabaId}/message_templates";
    }

    /**
     * Fetch an approved template definition from Meta WhatsApp Manager.
     *
     * @return array<string, mixed>|null
     */
    public function fetchTemplateDefinition(
        string $metaName,
        ?WhatsAppSetting $settings = null,
        ?string $language = null,
    ): ?array {
        $settings ??= $this->settingsService->current();
        $this->settingsService->assertReadyForLiveDispatch($settings);

        try {
            $response = Http::timeout(30)
                ->withToken((string) $settings->access_token)
                ->acceptJson()
                ->get($this->messageTemplatesEndpoint($settings), [
                    'name' => $metaName,
                    'fields' => 'name,language,status,components,parameter_format,category',
                    'limit' => 25,
                ]);

            $body = $response->json();
            if (! is_array($body) || ! $response->successful()) {
                Log::warning('whatsapp.meta_template.fetch.failed', [
                    'meta_name' => $metaName,
                    'http_status' => $response->status(),
                    'body' => $body,
                ]);

                return null;
            }

            foreach ($body['data'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                if (! in_array(strtoupper((string) ($row['status'] ?? '')), ['APPROVED', 'ACTIVE'], true)) {
                    continue;
                }

                if ($language !== null && ! $this->languageCodesMatch((string) $language, (string) ($row['language'] ?? ''))) {
                    continue;
                }

                return $row;
            }

            return null;
        } catch (\Throwable $exception) {
            Log::warning('whatsapp.meta_template.fetch.exception', [
                'meta_name' => $metaName,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Align CRM meta_components with the approved template structure in Meta.
     */
    public function syncTemplateStructure(
        MessageTemplate $template,
        ?WhatsAppSetting $settings = null,
    ): MessageTemplate {
        $settings ??= $this->settingsService->current();
        $metaName = $template->metaApiTemplateName();
        $definition = $this->fetchTemplateDefinition(
            $metaName,
            $settings,
            (string) $template->language_code,
        );

        if ($definition === null) {
            $definition = $this->fetchTemplateDefinition($metaName, $settings);
        }

        if ($definition === null) {
            throw ValidationException::withMessages([
                'template' => ["Template \"{$metaName}\" was not found as APPROVED in Meta WhatsApp Manager."],
            ]);
        }

        $parsed = $this->parseMetaTemplateStructure($definition);
        $existingMeta = is_array($template->meta_components) ? $template->meta_components : [];
        $sample = is_array($existingMeta['sample'] ?? null) ? $existingMeta['sample'] : [];
        $parsedSample = is_array($parsed['sample'] ?? null) ? $parsed['sample'] : [];

        $metaComponents = array_merge($existingMeta, $parsed);
        unset($metaComponents['buttons'], $metaComponents['sample'], $metaComponents['meta_body_text']);
        $metaComponents['body_parameters'] = $this->resolveBodyParameters(
            is_array($existingMeta['body_parameters'] ?? null) ? $existingMeta['body_parameters'] : [],
            is_array($parsed['body_parameters'] ?? null) ? $parsed['body_parameters'] : [],
            (string) ($parsed['meta_body_text'] ?? $template->body_template),
        );
        if (isset($parsed['buttons']) && is_array($parsed['buttons']) && $parsed['buttons'] !== []) {
            $metaComponents['buttons'] = $parsed['buttons'];
        }
        if ($parsedSample !== []) {
            $metaComponents['sample'] = $parsedSample;
        } elseif ($sample !== []) {
            $metaComponents['sample'] = $sample;
        }

        $metaStatus = strtoupper((string) ($definition['status'] ?? 'APPROVED'));
        $updates = [
            'meta_components' => $metaComponents,
            'meta_status' => $metaStatus,
            'meta_status_updated_at' => now(),
            'meta_status_payload' => $definition,
            'status' => $this->mapMetaEventToCrmStatus($metaStatus),
            'is_active' => in_array($metaStatus, ['APPROVED', 'REINSTATED'], true),
        ];

        $metaLanguage = (string) ($definition['language'] ?? '');
        if ($metaLanguage !== '') {
            $updates['language_code'] = $this->normalizeLanguageCode($metaLanguage);
        }

        if (filled($parsed['meta_body_text'] ?? null)) {
            $updates['body_template'] = (string) $parsed['meta_body_text'];
        }

        $template->update($updates);

        Log::info('whatsapp.meta_template.synced', [
            'template_id' => $template->id,
            'template_name' => $template->template_name,
            'meta_name' => $metaName,
            'language_code' => $updates['language_code'] ?? $template->language_code,
            'parameter_format' => $parsed['parameter_format'] ?? null,
            'body_parameters' => $parsed['body_parameters'] ?? [],
            'buttons' => $parsed['buttons'] ?? [],
        ]);

        return $template->fresh();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function parseMetaTemplateStructure(array $definition): array
    {
        $components = is_array($definition['components'] ?? null) ? $definition['components'] : [];
        $parameterFormat = strtoupper((string) ($definition['parameter_format'] ?? ''));
        $bodyParameters = [];
        $buttons = [];
        $bodyText = '';
        $sampleValues = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $type = strtoupper((string) ($component['type'] ?? ''));

            if ($type === 'BODY') {
                $bodyText = (string) ($component['text'] ?? '');
                $namedExamples = $component['example']['body_text_named_params'] ?? null;
                $fromExamples = [];
                $bodySampleValues = [];

                if (is_array($namedExamples) && $namedExamples !== []) {
                    foreach ($namedExamples as $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        $paramName = trim((string) ($item['param_name'] ?? ''));
                        if ($paramName !== '') {
                            $fromExamples[] = $paramName;
                            $example = $item['example'] ?? null;
                            if (is_string($example) && trim($example) !== '') {
                                $bodySampleValues[$paramName] = trim($example);
                            }
                        }
                    }
                    $parameterFormat = 'NAMED';
                }

                $fromText = [];
                if ($bodyText !== '' && preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $matches)) {
                    $fromText = array_map('strval', $matches[1]);
                    $parameterFormat = 'POSITIONAL';
                } elseif ($bodyText !== '' && preg_match_all('/\{\{([^}]+)\}\}/', $bodyText, $matches)) {
                    $fromText = array_values(array_map(static fn (string $name) => trim($name), $matches[1]));
                    if ($parameterFormat === '') {
                        $parameterFormat = 'NAMED';
                    }
                }

                // Meta example param names are canonical; merge any extras parsed from body text.
                if ($fromExamples !== []) {
                    $bodyParameters = $fromExamples;
                    foreach ($fromText as $paramName) {
                        if (! in_array($paramName, $bodyParameters, true)) {
                            $bodyParameters[] = $paramName;
                        }
                    }
                } else {
                    $bodyParameters = $fromText;
                }

                $positionalExamples = $component['example']['body_text'] ?? null;
                if ($bodySampleValues === [] && is_array($positionalExamples) && isset($positionalExamples[0]) && is_array($positionalExamples[0])) {
                    foreach ($positionalExamples[0] as $index => $example) {
                        if (! is_scalar($example)) {
                            continue;
                        }
                        $paramName = $bodyParameters[$index] ?? (string) ($index + 1);
                        $bodySampleValues[(string) $paramName] = trim((string) $example);
                    }
                }

                if ($bodySampleValues !== []) {
                    $sampleValues = array_merge($sampleValues, $bodySampleValues);
                }
            }

            if ($type === 'BUTTONS') {
                foreach ($component['buttons'] ?? [] as $index => $button) {
                    if (! is_array($button)) {
                        continue;
                    }
                    if (strtoupper((string) ($button['type'] ?? '')) !== 'URL') {
                        continue;
                    }

                    $url = (string) ($button['url'] ?? '');
                    if (! preg_match('/\{\{([^}]+)\}\}/', $url, $urlMatch)) {
                        continue;
                    }

                    $paramSource = trim((string) $urlMatch[1]);
                    $urlBase = preg_replace('/\{\{[^}]+\}\}.*$/', '', $url) ?? $url;

                    $buttons[] = [
                        'index' => (string) $index,
                        'sub_type' => 'url',
                        'parameter_source' => $paramSource,
                        'url_base' => $urlBase,
                    ];
                }
            }
        }

        $format = strtolower($parameterFormat === 'POSITIONAL' ? 'positional' : 'named');

        $result = [
            'parameter_format' => $format,
            'body_parameters' => array_values(array_filter($bodyParameters, fn ($value) => $value !== '')),
            'meta_body_text' => $bodyText,
        ];

        if ($buttons !== []) {
            $result['buttons'] = $buttons;
        } else {
            unset($result['buttons']);
        }

        if ($sampleValues !== []) {
            $result['sample'] = $sampleValues;
        }

        return $result;
    }

    /**
     * Prefer the fullest parameter list so Meta sync cannot shrink CRM mapping to a partial set.
     *
     * @param  list<string>  $existing
     * @param  list<string>  $parsed
     * @return list<string>
     */
    private function resolveBodyParameters(array $existing, array $parsed, string $bodyText): array
    {
        $fromText = [];
        if ($bodyText !== '' && preg_match_all('/\{\{([^}]+)\}\}/', $bodyText, $matches)) {
            $fromText = array_values(array_map(static fn (string $name) => trim($name), $matches[1]));
        }

        $best = [];
        foreach ([$fromText, $parsed, $existing] as $candidate) {
            if (count($candidate) > count($best)) {
                $best = $candidate;
            }
        }

        return array_values(array_filter($best, static fn (string $value) => $value !== ''));
    }

    private function mapMetaEventToCrmStatus(string $event): string
    {
        return match (strtoupper($event)) {
            'APPROVED', 'REINSTATED', 'UNARCHIVED' => MessageTemplate::STATUS_APPROVED,
            'REJECTED', 'DISABLED', 'DELETED', 'PENDING_DELETION', 'LIMIT_EXCEEDED' => MessageTemplate::STATUS_REJECTED,
            default => MessageTemplate::STATUS_PENDING,
        };
    }

    private function normalizeLanguageCode(string $language): string
    {
        $language = trim($language) ?: 'en';

        if (str_contains($language, '_')) {
            [$lang, $region] = explode('_', $language, 2);

            return strtolower($lang).'_'.strtoupper($region);
        }

        if (str_contains($language, '-')) {
            [$lang, $region] = explode('-', $language, 2);

            return strtolower($lang).'_'.strtoupper($region);
        }

        return strtolower($language);
    }

    private function languageCodesMatch(string $stored, string $incoming): bool
    {
        $stored = $this->normalizeLanguageCode($stored);
        $incoming = $this->normalizeLanguageCode($incoming);

        if ($stored === $incoming) {
            return true;
        }

        $storedBase = strtok($stored, '_') ?: $stored;
        $incomingBase = strtok($incoming, '_') ?: $incoming;

        return $storedBase !== '' && $storedBase === $incomingBase;
    }

    private function exampleValueForPlaceholder(string $placeholder): string
    {
        return match ($placeholder) {
            '{{name}}', '{{client_name}}', '{{1}}' => 'Ramesh Gupta',
            '{{firm_name}}' => 'Sample Firm',
            '{{task_name}}' => 'Filing GSTR',
            '{{task_date}}', '{{scheduled_date}}', '{{expected_completion}}' => '24-June-2025',
            '{{assigned_staff}}', '{{employee_name}}' => 'Vikash, Nitish',
            '{{task_status}}' => 'Scheduled',
            '{{2}}' => 'LawSeva',
            default => 'Sample',
        };
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function extractErrorMessage(array $providerResponse): string
    {
        if (isset($providerResponse['error']) && is_array($providerResponse['error'])) {
            $error = $providerResponse['error'];
            $message = (string) ($error['message'] ?? 'Meta template API error');
            $code = $error['code'] ?? null;

            return trim($message.($code ? " (code: {$code})" : ''));
        }

        return (string) ($providerResponse['message'] ?? $providerResponse['raw'] ?? 'Meta template API returned an error.');
    }
}
