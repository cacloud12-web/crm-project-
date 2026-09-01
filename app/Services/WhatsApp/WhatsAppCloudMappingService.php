<?php

namespace App\Services\WhatsApp;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\MessageTemplate;
use App\Models\WhatsAppSetting;
use App\Rules\ValidMobileNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WhatsAppCloudMappingService
{
    public function __construct(
        private readonly WhatsAppSettingsService $settingsService,
        private readonly WhatsAppTemplateService $templateService,
        private readonly WhatsAppMetaTemplateService $metaTemplateService,
    ) {}

    /**
     * Resolve CRM variables for a lead.
     *
     * @return array<string, string>
     */
    public function resolveVariables(CaMaster $lead): array
    {
        $lead->loadMissing(['city', 'state', 'sourceLead']);

        $assignment = LeadAssignmentEngine::query()
            ->with('employee')
            ->where('ca_id', $lead->ca_id)
            ->where('status', 'Active')
            ->orderByDesc('assigned_date')
            ->first();

        $assignedStaff = LeadAssignmentEngine::query()
            ->with('employee')
            ->where('ca_id', $lead->ca_id)
            ->where('status', 'Active')
            ->orderByDesc('assigned_date')
            ->get()
            ->map(fn ($row) => $row->employee?->name)
            ->filter()
            ->unique()
            ->implode(', ');

        $latestTask = FollowUp::query()
            ->where('ca_id', $lead->ca_id)
            ->orderByDesc('scheduled_date')
            ->orderByDesc('followup_id')
            ->first();

        $demoFollowUp = $latestTask ?? FollowUp::query()
            ->where('ca_id', $lead->ca_id)
            ->where(function ($query) {
                $query->where('followup_type', 'ilike', '%demo%')
                    ->orWhere('status', 'ilike', '%demo%');
            })
            ->orderByDesc('scheduled_date')
            ->first();

        $demoDate = '';
        $demoTime = '';
        $meetingLink = '';
        if ($demoFollowUp?->scheduled_date) {
            $scheduled = Carbon::parse($demoFollowUp->scheduled_date);
            $demoDate = $scheduled->format('d-M-Y');
            $demoTime = $scheduled->format('h:i A');
        } elseif ($demoFollowUp?->next_followup_date) {
            $scheduled = Carbon::parse($demoFollowUp->next_followup_date);
            $demoDate = $scheduled->format('d-M-Y');
            $demoTime = $scheduled->format('h:i A');
        }
        $meetingLink = trim((string) ($demoFollowUp?->meeting_link ?? ''));
        if ($meetingLink === '') {
            $meetingLink = (string) config('crm_defaults.template_preview.meeting_link', '');
        }

        $taskName = trim((string) ($latestTask?->followup_type ?: $latestTask?->notes ?: ''));
        $taskDate = $demoDate !== '' ? $demoDate : now()->format('d-F-Y');
        $expectedCompletion = $demoDate !== '' ? $demoDate : now()->format('d-F-Y');

        return [
            '{{name}}' => (string) ($lead->ca_name ?? ''),
            '{{client_name}}' => (string) ($lead->ca_name ?? ''),
            '{{CLIENT_NAME}}' => (string) ($lead->ca_name ?? ''),
            '{{firm_name}}' => (string) ($lead->firm_name ?? ''),
            '{{mobile}}' => (string) ($lead->mobile_no ?? ''),
            '{{city}}' => (string) ($lead->city?->city_name ?? ''),
            '{{state}}' => (string) ($lead->state?->state_name ?? ''),
            '{{demo_date}}' => $demoDate,
            '{{demo_time}}' => $demoTime,
            '{{date}}' => $demoDate,
            '{{time}}' => $demoTime,
            '{{link}}' => $meetingLink,
            '{{MEETING_LINK}}' => $meetingLink,
            '{{employee_name}}' => (string) ($assignment?->employee?->name ?? ''),
            '{{assigned_staff}}' => $assignedStaff !== '' ? $assignedStaff : ((string) ($assignment?->employee?->name ?? '') !== '' ? (string) $assignment?->employee?->name : 'Not assigned'),
            '{{task_name}}' => $taskName !== '' ? $taskName : 'Task',
            '{{task_status}}' => (string) ($latestTask?->status ?? 'Scheduled'),
            '{{task_date}}' => $taskDate,
            '{{expected_completion}}' => $expectedCompletion,
            '{{scheduled_date}}' => $taskDate,
            '{{scheduled_time}}' => $demoTime !== '' ? $demoTime : now()->format('h:i A'),
            '{{AMOUNT}}' => (string) config('whatsapp_cloud.meta_parameter_fallbacks.amount', 'N/A'),
            '{{EXPENSE_DATE}}' => (string) config('whatsapp_cloud.meta_parameter_fallbacks.expense_date', 'N/A'),
            '{{EXPENSE_CATEGORY}}' => (string) config('whatsapp_cloud.meta_parameter_fallbacks.expense_category', 'N/A'),
            '{{EXPENSE_ID}}' => (string) config('whatsapp_cloud.meta_parameter_fallbacks.expense_id', 'N/A'),
            '{{SERVICE_NAME}}' => (string) config('whatsapp_cloud.meta_parameter_fallbacks.service_name', 'N/A'),
            '{{INVOICE_DATE}}' => (string) config('whatsapp_cloud.meta_parameter_fallbacks.invoice_date', 'N/A'),
        ];
    }

    public function renderTemplateBody(string $bodyTemplate, array $variables): string
    {
        return strtr($bodyTemplate, $variables);
    }

    public function normalizeRecipientMobile(?string $mobile): ?string
    {
        if ($mobile === null || trim($mobile) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $mobile) ?? '';

        if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
            return $digits;
        }

        if (strlen($digits) === 10) {
            return '91'.$digits;
        }

        return $digits !== '' ? $digits : null;
    }

    /**
     * Build Meta WhatsApp Cloud API payload (mapping only — no HTTP).
     *
     * @return array<string, mixed>
     */
    public function buildCloudPayload(
        CaMaster $lead,
        MessageTemplate $template,
        ?WhatsAppSetting $settings = null,
        array $variableOverrides = [],
    ): array {
        $settings ??= $this->settingsService->current();
        $this->metaTemplateService->assertTemplateReadyForMetaSend($template);
        $this->metaTemplateService->assertFlowButtonsReadyForMetaSend($template, $settings);
        $variables = $this->resolveTemplateVariables($template, $lead, $variableOverrides);
        $bodyText = $this->renderTemplateBody($template->body_template, $variables);
        $parameters = $this->sanitizeMetaBodyParameters(
            $this->resolveBodyParameterValues($template, $variables),
            $template,
        );

        $recipient = $this->normalizeRecipientMobile($lead->mobile_no);

        $templatePayload = [
            'name' => $template->metaApiTemplateName(),
            'language' => [
                'code' => $template->metaApiLanguageCode(),
            ],
        ];

        $components = $this->buildMetaTemplateComponents($template, $parameters, $variables);
        if ($components !== []) {
            $templatePayload['components'] = $components;
        }

        $requestBody = [
            'messaging_product' => 'whatsapp',
            'to' => $recipient,
            'type' => 'template',
            'template' => $templatePayload,
        ];

        return [
            'mapping_version' => 'whatsapp_cloud_v1',
            'endpoint' => $this->buildEndpoint($settings),
            'auth' => [
                'type' => 'bearer',
                'access_token_configured' => $settings->hasAccessToken(),
            ],
            'meta' => [
                'phone_number_id' => $settings->phone_number_id,
                'business_account_id' => $settings->business_account_id,
                'api_version' => $settings->api_version,
            ],
            'request_body' => $requestBody,
            'rendered_message' => $bodyText,
            'crm_mapping' => [
                'ca_id' => $lead->ca_id,
                'mobile_no' => $lead->mobile_no,
                'template_name' => $template->metaApiTemplateName(),
                'crm_template_name' => $template->template_name,
                'language_code' => $template->language_code,
                'variables' => $variables,
                'body_parameters' => $parameters,
            ],
        ];
    }

    /**
     * Validate Meta Cloud API credentials and template (campaign-level, not per lead).
     *
     * @return array<int, string>
     */
    public function validateDispatchSettings(
        MessageTemplate $template,
        ?WhatsAppSetting $settings = null,
    ): array {
        $settings ??= $this->settingsService->current();
        $errors = [];

        if (! $template->isApproved()) {
            $errors[] = 'Template '.$template->template_name.' is not approved.';
        }

        if (! filled($template->meta_api_name)) {
            $errors[] = 'Template '.$template->template_name.' is not mapped to a Meta template. Set meta_api_name before sending.';
        }

        if (! filled($template->language_code)) {
            $errors[] = 'Template language code is required.';
        }

        if (! filled($settings->phone_number_id)) {
            $errors[] = 'Phone Number ID is not configured in WhatsApp settings.';
        }

        if (! filled($settings->business_account_id)) {
            $errors[] = 'Business Account ID is not configured in WhatsApp settings.';
        }

        if (! $settings->hasAccessToken()) {
            $errors[] = 'Permanent Access Token is not configured in WhatsApp settings.';
        }

        if (! $settings->is_active) {
            $errors[] = 'WhatsApp provider is not active.';
        }

        if (! $settings->isLiveMode()) {
            $errors[] = 'WhatsApp must be in Live mode to send messages.';
        }

        if ($this->requiresDocumentHeader($template) && $this->buildHeaderComponent($template) === null) {
            $errors[] = 'Template '.$template->template_name.' requires a document header URL. Set WHATSAPP_INVOICE_DOCUMENT_URL or WHATSAPP_TASK_DOCUMENT_URL in .env.';
        }

        return $errors;
    }

    private function requiresDocumentHeader(MessageTemplate $template): bool
    {
        $metaComponents = $template->meta_components;

        return is_array($metaComponents)
            && isset($metaComponents['header']['type'])
            && strtolower((string) $metaComponents['header']['type']) === 'document';
    }

    /**
     * Validate a single lead can receive a WhatsApp message.
     *
     * @return array<int, string>
     */
    public function validateLeadRecipient(CaMaster $lead): array
    {
        if (! $this->isValidMobile($lead->mobile_no)) {
            return ['Lead '.$lead->ca_id.' has an invalid or missing mobile number.'];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    public function validateCampaignPrerequisites(
        CaMaster $lead,
        MessageTemplate $template,
        ?WhatsAppSetting $settings = null,
    ): array {
        return array_merge(
            $this->validateDispatchSettings($template, $settings),
            $this->validateLeadRecipient($lead),
        );
    }

    public function leadHasActiveAssignment(CaMaster $lead): bool
    {
        return LeadAssignmentEngine::query()
            ->where('ca_id', $lead->ca_id)
            ->where('status', 'Active')
            ->exists();
    }

    public function buildMessagesEndpoint(WhatsAppSetting $settings): string
    {
        return str_replace(
            ['{graph_base_url}', '{api_version}', '{phone_number_id}'],
            [
                rtrim((string) config('whatsapp_cloud.graph_base_url'), '/'),
                $settings->api_version,
                $settings->phone_number_id ?? '{phone_number_id}',
            ],
            (string) config('whatsapp_cloud.messages_endpoint_pattern'),
        );
    }

    /**
     * Build a template message payload for a test mobile number (no lead required).
     *
     * @return array<string, mixed>
     */
    public function buildTestTemplatePayload(
        MessageTemplate $template,
        string $mobileNo,
        ?WhatsAppSetting $settings = null,
    ): array {
        $settings ??= $this->settingsService->current();
        $this->metaTemplateService->assertTemplateReadyForMetaSend($template, $settings);
        $this->metaTemplateService->assertFlowButtonsReadyForMetaSend($template, $settings);
        $recipient = $this->normalizeRecipientMobile($mobileNo);
        $variables = $this->resolveTemplateVariables($template);
        $bodyText = $this->renderTemplateBody($template->body_template, $variables);
        $parameters = $this->sanitizeMetaBodyParameters(
            $this->resolveBodyParameterValues($template, $variables),
            $template,
        );

        $templatePayload = [
            'name' => $template->metaApiTemplateName(),
            'language' => ['code' => $template->metaApiLanguageCode()],
        ];

        $components = $this->buildMetaTemplateComponents($template, $parameters, $variables);
        if ($components !== []) {
            $templatePayload['components'] = $components;
        }

        return [
            'mapping_version' => 'whatsapp_cloud_v1',
            'endpoint' => $this->buildMessagesEndpoint($settings),
            'request_body' => [
                'messaging_product' => 'whatsapp',
                'to' => $recipient,
                'type' => 'template',
                'template' => $templatePayload,
            ],
            'rendered_message' => $bodyText,
            'crm_mapping' => [
                'template_name' => $template->metaApiTemplateName(),
                'crm_template_name' => $template->template_name,
                'language_code' => $template->language_code,
                'variables' => $variables,
                'body_parameters' => $parameters,
            ],
        ];
    }

    /**
     * @return list<array{type: string, parameters: list<array<string, mixed>>}>
     */
    public function buildMetaTemplateComponents(MessageTemplate $template, array $bodyParameters, array $variables = []): array
    {
        $components = [];
        $headerComponent = $this->buildHeaderComponent($template);

        if ($headerComponent !== null) {
            $components[] = $headerComponent;
        }

        if ($bodyParameters !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => $this->buildMetaBodyParameters($template, $bodyParameters),
            ];
        }

        $components = array_merge(
            $components,
            $this->buildMetaFlowButtonComponents($template),
            $this->buildMetaButtonComponents($template, $variables),
        );

        $this->logMetaTemplateSendComponents($template, $components);

        return $components;
    }

    /**
     * Meta FLOW buttons require a send-time button component with flow_token.
     * Index is the zero-based position in Meta's approved BUTTONS array.
     *
     * @return list<array{type: string, sub_type: string, index: string, parameters: list<array<string, mixed>>}>
     */
    private function buildMetaFlowButtonComponents(MessageTemplate $template): array
    {
        $flowButtons = $this->metaTemplateService->resolveFlowButtons($template);
        if ($flowButtons === []) {
            return [];
        }

        $components = [];
        foreach ($flowButtons as $button) {
            if (! is_array($button) || ! ($button['requires_send_parameter'] ?? true)) {
                continue;
            }

            $components[] = [
                'type' => 'button',
                'sub_type' => 'flow',
                'index' => (string) ($button['index'] ?? '0'),
                'parameters' => [
                    [
                        'type' => 'action',
                        'action' => [
                            'flow_token' => 'unused',
                        ],
                    ],
                ],
            ];
        }

        return $components;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    private function logMetaTemplateSendComponents(MessageTemplate $template, array $components): void
    {
        $buttonComponents = array_values(array_filter(
            $components,
            static fn ($component) => is_array($component) && ($component['type'] ?? '') === 'button',
        ));

        Log::info('whatsapp.meta_template.send_components', [
            'template_name' => $template->template_name,
            'meta_template_name' => $template->metaApiTemplateName(),
            'components' => $components,
            'button_components' => array_map(static function (array $component): array {
                return [
                    'type' => $component['type'] ?? null,
                    'sub_type' => $component['sub_type'] ?? null,
                    'index' => $component['index'] ?? null,
                    'parameters' => $component['parameters'] ?? null,
                ];
            }, $buttonComponents),
        ]);
    }

    /**
     * Meta templates created in WhatsApp Manager with lowercase named placeholders
     * (e.g. {{name}}) require parameter_name on each body parameter when sending.
     *
     * @return list<array<string, mixed>>
     */
    public function buildMetaBodyParameters(MessageTemplate $template, array $bodyParameters): array
    {
        $meta = is_array($template->meta_components) ? $template->meta_components : [];
        $format = (string) ($meta['parameter_format'] ?? 'named');
        $definedNames = is_array($meta['body_parameters'] ?? null) ? $meta['body_parameters'] : [];
        $placeholders = $this->extractBodyPlaceholdersInOrder((string) $template->body_template);
        $parameters = [];

        foreach ($bodyParameters as $index => $text) {
            $entry = [
                'type' => 'text',
                'text' => $this->sanitizeMetaParameterText($text),
            ];

            if ($format === 'named') {
                $placeholderName = isset($placeholders[$index])
                    ? trim($placeholders[$index], '{}')
                    : '';
                $definedName = isset($definedNames[$index])
                    ? trim((string) $definedNames[$index])
                    : '';

                // Meta requires parameter_name to match approved body placeholders exactly (including spaces).
                $parameterName = $placeholderName !== ''
                    ? $placeholderName
                    : ($definedName !== ''
                        ? $definedName
                        : (string) ($this->metaParameterNameForPlaceholder($placeholders[$index] ?? null) ?? ''));

                if ($parameterName !== '' && ! ctype_digit($parameterName)) {
                    $entry['parameter_name'] = $parameterName;
                }
            }

            $parameters[] = $entry;
        }

        return $parameters;
    }

    /**
     * Build parameter values using meta_components.body_parameters when configured.
     *
     * @return list<string>
     */
    public function resolveBodyParameterValues(MessageTemplate $template, array $variables): array
    {
        $meta = is_array($template->meta_components) ? $template->meta_components : [];
        $defined = $meta['body_parameters'] ?? null;

        if (is_array($defined) && $defined !== []) {
            $values = [];
            $orderedPlaceholders = $this->extractBodyPlaceholdersInOrder((string) $template->body_template);

            foreach ($defined as $index => $paramName) {
                $name = (string) $paramName;
                if (ctype_digit($name)) {
                    $placeholder = $orderedPlaceholders[(int) $name - 1] ?? '{{'.$name.'}}';
                } else {
                    $placeholder = '{{'.$name.'}}';
                }

                if (! is_int($index)) {
                    $index = array_search($paramName, $defined, true);
                }

                if (is_int($index) && isset($orderedPlaceholders[$index])) {
                    $templatePlaceholder = $orderedPlaceholders[$index];
                    if ($templatePlaceholder !== $placeholder && array_key_exists($templatePlaceholder, $variables)) {
                        $values[] = (string) $variables[$templatePlaceholder];

                        continue;
                    }
                }

                if (array_key_exists($placeholder, $variables)) {
                    $values[] = (string) $variables[$placeholder];

                    continue;
                }

                $upper = '{{'.strtoupper($name).'}}';
                $values[] = (string) ($variables[$upper] ?? '');
            }

            return $values;
        }

        return $this->extractBodyParametersInOrder((string) $template->body_template, $variables);
    }

    /**
     * @return list<string>
     */
    public function extractBodyPlaceholdersInOrder(string $bodyTemplate): array
    {
        preg_match_all('/\{\{[^}]+\}\}/', $bodyTemplate, $matches);

        return array_values($matches[0] ?? []);
    }

    /**
     * Meta templates with static URL or phone buttons must not receive send-time button
     * components. Dynamic URL buttons ({{var}} in the approved URL) are handled separately.
     *
     * @return list<array{type: string, sub_type: string, index: string, parameters: list<array<string, mixed>>}>
     */
    private function buildMetaButtonComponents(MessageTemplate $template, array $variables): array
    {
        if (! (bool) config('whatsapp_cloud.enable_template_button_parameters', false)) {
            return [];
        }

        $meta = is_array($template->meta_components) ? $template->meta_components : [];
        $buttons = $meta['buttons'] ?? [];
        if (! is_array($buttons) || $buttons === []) {
            return [];
        }

        $components = [];
        foreach ($buttons as $button) {
            if (! is_array($button)) {
                continue;
            }

            if (! ($button['requires_send_parameter'] ?? false)) {
                continue;
            }

            $source = (string) ($button['parameter_source'] ?? $button['parameter'] ?? 'link');
            if (ctype_digit($source)) {
                $orderedPlaceholders = $this->extractBodyPlaceholdersInOrder((string) $template->body_template);
                $placeholder = $orderedPlaceholders[(int) $source - 1] ?? '{{'.$source.'}}';
            } else {
                $placeholder = '{{'.$source.'}}';
            }
            $text = (string) ($variables[$placeholder] ?? $variables['{{link}}'] ?? $variables['{{'.strtoupper($source).'}}'] ?? '');
            if (trim($text) === '') {
                $text = (string) config('whatsapp_cloud.meta_parameter_fallbacks.meeting_link', 'https://meet.google.com/ouq-sxne-jwn');
            }

            $entry = [
                'type' => 'text',
                'text' => $this->sanitizeMetaParameterText(
                    $this->normalizeButtonParameterText(trim($text), $button),
                ),
            ];

            $components[] = [
                'type' => 'button',
                'sub_type' => (string) ($button['sub_type'] ?? 'url'),
                'index' => (string) ($button['index'] ?? '0'),
                'parameters' => [$entry],
            ];
        }

        return $components;
    }

    /**
     * URL button parameters must be the dynamic suffix only, not parameter_name.
     *
     * @param  array<string, mixed>  $button
     */
    private function normalizeButtonParameterText(string $text, array $button): string
    {
        if ($text === '') {
            return $text;
        }

        $baseUrl = rtrim(trim((string) ($button['url_base'] ?? '')), '/');
        if ($baseUrl === '') {
            return $text;
        }

        $normalizedText = rtrim($text, '/');
        if ($normalizedText === $baseUrl || str_starts_with($normalizedText, $baseUrl.'/')) {
            $suffix = ltrim(substr($normalizedText, strlen($baseUrl)), '/');
            if ($suffix !== '') {
                return $suffix;
            }
        }

        $sampleSuffix = trim((string) ($button['sample_suffix'] ?? ''));
        if ($sampleSuffix !== '') {
            return ltrim($sampleSuffix, '/');
        }

        $configuredSuffix = trim((string) config('whatsapp_cloud.meta_parameter_fallbacks.button_url_suffix', ''));
        if ($configuredSuffix !== '') {
            return ltrim($configuredSuffix, '/');
        }

        // Meta rejects empty URL button suffixes (#100 / #131009).
        return ltrim((string) config('whatsapp_cloud.meta_parameter_fallbacks.button_url_suffix', 'ouq-sxne-jwn'), '/');
    }

    private function sanitizeMetaParameterText(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text));

        return preg_replace('/\s{2,}/', ' ', $text) ?? $text;
    }

    private function metaParameterNameForPlaceholder(?string $placeholder): ?string
    {
        if (! is_string($placeholder) || $placeholder === '') {
            return null;
        }

        $name = trim($placeholder, '{}');
        if ($name === '' || ctype_digit($name)) {
            return null;
        }

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            return null;
        }

        return $name;
    }

    /**
     * @return list<array{type: string, parameters: list<array{type: string, text: string}>}>
     */
    public function buildTemplateComponentsPublic(array $parameters): array
    {
        if ($parameters === []) {
            return [];
        }

        return [[
            'type' => 'body',
            'parameters' => array_map(
                fn (string $text) => ['type' => 'text', 'text' => $text],
                $parameters,
            ),
        ]];
    }

    /**
     * @return array{type: string, parameters: list<array<string, mixed>>}|null
     */
    private function buildHeaderComponent(MessageTemplate $template): ?array
    {
        $metaComponents = $template->meta_components;
        if (! is_array($metaComponents) || ! isset($metaComponents['header']) || ! is_array($metaComponents['header'])) {
            return null;
        }

        $header = $metaComponents['header'];
        $type = strtolower((string) ($header['type'] ?? ''));

        if ($type !== 'document') {
            return null;
        }

        $document = $header['document'] ?? [];
        if (! is_array($document)) {
            $document = [];
        }

        $defaults = (array) config('whatsapp_cloud.default_header_documents.'.$template->template_name, []);
        $link = $document['link'] ?? $defaults['link'] ?? null;
        $filename = $document['filename'] ?? $defaults['filename'] ?? 'document.pdf';

        if (! filled($link)) {
            return null;
        }

        return [
            'type' => 'header',
            'parameters' => [[
                'type' => 'document',
                'document' => [
                    'link' => (string) $link,
                    'filename' => (string) $filename,
                ],
            ]],
        ];
    }

    /**
     * Resolve placeholder values for a template (named {{name}} or numbered {{1}}).
     *
     * @return array<string, string>
     */
    public function resolveTemplateVariables(MessageTemplate $template, ?CaMaster $lead = null, array $overrides = []): array
    {
        $leadVariables = $lead
            ? $this->resolveVariables($lead)
            : $this->baseDummyVariables();
        $resolved = $leadVariables;

        $map = $template->variable_map;
        if (! is_array($map) || $map === []) {
            $map = (array) config('whatsapp_cloud.template_variables', []);
        }

        foreach ($map as $placeholder => $source) {
            if (! is_string($placeholder) || ! is_string($source)) {
                continue;
            }

            if (str_starts_with($source, 'static:')) {
                $resolved[$placeholder] = substr($source, 7);

                continue;
            }

            if (isset($leadVariables[$placeholder])) {
                $resolved[$placeholder] = $leadVariables[$placeholder];

                continue;
            }

            $namedKey = str_starts_with($source, '{{') ? $source : '{{'.$source.'}}';
            if (isset($leadVariables[$namedKey])) {
                $resolved[$placeholder] = $leadVariables[$namedKey];

                continue;
            }

            if ($lead) {
                $resolved[$placeholder] = match ($source) {
                    'ca_name', 'client_name' => (string) ($lead->ca_name ?? ''),
                    'firm_name' => (string) ($lead->firm_name ?? ''),
                    'mobile_no', 'mobile' => (string) ($lead->mobile_no ?? ''),
                    'demo_date', 'renewal_due_date', 'renewal_date', 'payment_date', 'expiry_date' => (string) (
                        $leadVariables['{{date}}']
                        ?: config('whatsapp_cloud.meta_parameter_fallbacks.'.$source)
                        ?: config('whatsapp_cloud.meta_parameter_fallbacks.default', 'N/A')
                    ),
                    'demo_time' => (string) (
                        $leadVariables['{{time}}']
                        ?: config('whatsapp_cloud.meta_parameter_fallbacks.demo_time')
                        ?: now()->format('h:i A')
                    ),
                    'meeting_link' => (string) (
                        $leadVariables['{{link}}']
                        ?: config('whatsapp_cloud.meta_parameter_fallbacks.meeting_link', 'https://meet.google.com/ouq-sxne-jwn')
                    ),
                    'amount', 'renewal_amount' => (string) (
                        config('whatsapp_cloud.meta_parameter_fallbacks.'.$source)
                        ?: config('whatsapp_cloud.meta_parameter_fallbacks.amount', 'N/A')
                    ),
                    'subscription_plan', 'plan_name' => (string) (
                        config('whatsapp_cloud.meta_parameter_fallbacks.'.$source)
                        ?: config('whatsapp_cloud.meta_parameter_fallbacks.subscription_plan', 'Professional Plan')
                    ),
                    'expense_date', 'expense_category', 'expense_id',
                    'service_name', 'invoice_date', 'invoice_amount', 'due_date' => (string) (
                        config('whatsapp_cloud.meta_parameter_fallbacks.'.$source)
                        ?: config('whatsapp_cloud.meta_parameter_fallbacks.default', 'N/A')
                    ),
                    default => $leadVariables[$namedKey] ?? $leadVariables[$placeholder] ?? '',
                };
            }
        }

        if (! $lead) {
            $resolved = $this->applyTemplateSampleDefaults($template, $resolved);
        } else {
            $resolved = $this->applyTemplateSampleDefaultsForEmpty($template, $resolved);
        }

        preg_match_all('/\{\{[^}]+\}\}/', (string) $template->body_template, $matches);
        foreach (array_unique($matches[0] ?? []) as $placeholder) {
            if (! array_key_exists($placeholder, $resolved) || trim((string) $resolved[$placeholder]) === '') {
                $resolved[$placeholder] = $this->sampleValueForPlaceholder($template, $placeholder)
                    ?? $leadVariables[$placeholder]
                    ?? (string) config('whatsapp_cloud.meta_parameter_fallbacks.default', 'N/A');
            }
        }

        foreach ($overrides as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $placeholder = str_starts_with($key, '{{') ? $key : '{{'.strtoupper($key).'}}';
            $resolved[$placeholder] = (string) $value;
        }

        return $resolved;
    }

    /**
     * Prefer Meta-approved sample values from template config for test/preview sends.
     *
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function applyTemplateSampleDefaults(MessageTemplate $template, array $variables): array
    {
        $meta = is_array($template->meta_components) ? $template->meta_components : [];
        $sample = is_array($meta['sample'] ?? null) ? $meta['sample'] : [];

        foreach ($sample as $key => $value) {
            if (! is_string($key) || ! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $text = $this->sanitizeMetaParameterText((string) $value);
            $variables['{{'.$key.'}}'] = $text;
            $variables['{{'.strtoupper($key).'}}'] = $text;
        }

        return $variables;
    }

    /**
     * Fill only empty placeholders from Meta-approved template samples (campaign sends with partial lead data).
     *
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function applyTemplateSampleDefaultsForEmpty(MessageTemplate $template, array $variables): array
    {
        $meta = is_array($template->meta_components) ? $template->meta_components : [];
        $sample = is_array($meta['sample'] ?? null) ? $meta['sample'] : [];

        foreach ($sample as $key => $value) {
            if (! is_string($key) || ! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $placeholder = '{{'.$key.'}}';
            if (! array_key_exists($placeholder, $variables) || trim((string) $variables[$placeholder]) === '') {
                $text = $this->sanitizeMetaParameterText((string) $value);
                $variables[$placeholder] = $text;
            }
        }

        return $variables;
    }

    private function sampleValueForPlaceholder(MessageTemplate $template, string $placeholder): ?string
    {
        $meta = is_array($template->meta_components) ? $template->meta_components : [];
        $sample = is_array($meta['sample'] ?? null) ? $meta['sample'] : [];
        $name = trim($placeholder, '{}');

        if ($name !== '' && isset($sample[$name]) && trim((string) $sample[$name]) !== '') {
            return $this->sanitizeMetaParameterText((string) $sample[$name]);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function extractBodyParameters(string $bodyTemplate, array $variables): array
    {
        return $this->extractBodyParametersInOrder($bodyTemplate, $variables);
    }

    /**
     * @return list<string>
     */
    public function extractBodyParametersInOrder(string $bodyTemplate, array $variables): array
    {
        preg_match_all('/\{\{[^}]+\}\}/', $bodyTemplate, $matches);
        $parameters = [];

        foreach ($matches[0] ?? [] as $key) {
            $parameters[] = (string) ($variables[$key] ?? '');
        }

        return $parameters;
    }

    /**
     * @return list<string>
     */
    public function extractBodyPlaceholders(string $bodyTemplate): array
    {
        return $this->extractBodyPlaceholdersInOrder($bodyTemplate);
    }

    /**
     * Meta rejects empty text body parameters (error #131008).
     *
     * @param  list<string>  $parameters
     * @return list<string>
     */
    public function sanitizeMetaBodyParameters(array $parameters, ?MessageTemplate $template = null): array
    {
        if ($parameters === []) {
            return [];
        }

        $placeholders = [];
        if ($template !== null) {
            $meta = is_array($template->meta_components) ? $template->meta_components : [];
            if (is_array($meta['body_parameters'] ?? null) && $meta['body_parameters'] !== []) {
                foreach ($meta['body_parameters'] as $paramName) {
                    $name = (string) $paramName;
                    $placeholders[] = ctype_digit($name) ? '{{'.$name.'}}' : '{{'.$name.'}}';
                }
            } else {
                $placeholders = $this->extractBodyPlaceholdersInOrder((string) $template->body_template);
            }
        }

        $variableMap = is_array($template?->variable_map) ? $template->variable_map : [];
        $sample = is_array($template?->meta_components['sample'] ?? null)
            ? $template->meta_components['sample']
            : [];
        $bodyParameterNames = is_array($template?->meta_components['body_parameters'] ?? null)
            ? $template->meta_components['body_parameters']
            : [];

        return array_map(function (string $value, int $index) use ($placeholders, $variableMap, $sample, $bodyParameterNames) {
            if (trim($value) !== '') {
                return trim($value);
            }

            $paramName = isset($bodyParameterNames[$index]) ? (string) $bodyParameterNames[$index] : null;
            if ($paramName !== null && isset($sample[$paramName]) && trim((string) $sample[$paramName]) !== '') {
                return trim((string) $sample[$paramName]);
            }

            $placeholder = $placeholders[$index] ?? null;
            $source = is_string($placeholder) ? ($variableMap[$placeholder] ?? null) : null;

            if (is_string($source) && str_starts_with($source, 'static:')) {
                return substr($source, 7);
            }

            if (is_string($source)) {
                $fallback = config('whatsapp_cloud.meta_parameter_fallbacks.'.$source);
                if (is_string($fallback) && $fallback !== '') {
                    return $fallback;
                }
            }

            return (string) config('whatsapp_cloud.meta_parameter_fallbacks.default', 'N/A');
        }, $parameters, array_keys($parameters));
    }

    /**
     * @return array<string, string>
     */
    public function dummyVariablesForTemplate(MessageTemplate $template): array
    {
        return $this->resolveTemplateVariables($template, null);
    }

    /**
     * @return array<string, string>
     */
    private function baseDummyVariables(): array
    {
        $fallbacks = (array) config('whatsapp_cloud.meta_parameter_fallbacks', []);

        $preview = config('crm_defaults.template_preview', []);

        return [
            '{{name}}' => $preview['ca_name'] ?? 'Sample Client',
            '{{client_name}}' => $preview['ca_name'] ?? 'Sample Client',
            '{{CLIENT_NAME}}' => $preview['ca_name'] ?? 'Sample Client',
            '{{firm_name}}' => $preview['firm_name'] ?? 'Sample Firm',
            '{{mobile}}' => '9000000000',
            '{{city}}' => $preview['city'] ?? 'Sample City',
            '{{state}}' => $preview['state'] ?? 'Sample State',
            '{{demo_date}}' => now()->format('d M Y'),
            '{{demo_time}}' => now()->format('h:i A'),
            '{{date}}' => now()->format('d-M-Y'),
            '{{time}}' => now()->format('h:i A'),
            '{{link}}' => (string) config('crm_defaults.template_preview.meeting_link', 'https://meet.google.com/ouq-sxne-jwn'),
            '{{MEETING_LINK}}' => (string) config('crm_defaults.template_preview.meeting_link', 'https://meet.google.com/ouq-sxne-jwn'),
            '{{employee_name}}' => 'CRM Test',
            '{{task_name}}' => 'Follow-up Call',
            '{{task_status}}' => 'Scheduled',
            '{{scheduled_date}}' => now()->format('d M Y'),
            '{{scheduled_time}}' => now()->format('h:i A'),
            '{{AMOUNT}}' => (string) ($fallbacks['amount'] ?? '2,500'),
            '{{EXPENSE_DATE}}' => (string) ($fallbacks['expense_date'] ?? '10-July-2026'),
            '{{EXPENSE_CATEGORY}}' => (string) ($fallbacks['expense_category'] ?? 'Travel'),
            '{{EXPENSE_ID}}' => (string) ($fallbacks['expense_id'] ?? 'EXP-2026-0001'),
            '{{SERVICE_NAME}}' => (string) ($fallbacks['service_name'] ?? 'GST Return Filing'),
            '{{INVOICE_DATE}}' => (string) ($fallbacks['invoice_date'] ?? '10-July-2026'),
            '{{1}}' => $preview['ca_name'] ?? 'Sample Client',
            '{{2}}' => 'GST Return',
            '{{3}}' => '24-June-2025',
            '{{4}}' => '10,150',
            '{{5}}' => '28-June-2025',
        ];
    }

    /**
     * @throws ValidationException
     */
    public function assertCampaignMappable(
        CaMaster $lead,
        MessageTemplate $template,
        ?WhatsAppSetting $settings = null,
    ): void {
        $errors = $this->validateCampaignPrerequisites($lead, $template, $settings);

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'campaign' => $errors,
            ]);
        }
    }

    public function resolveEmployeeId(CaMaster $lead): ?int
    {
        $assignment = LeadAssignmentEngine::query()
            ->where('ca_id', $lead->ca_id)
            ->where('status', 'Active')
            ->orderByDesc('assigned_date')
            ->value('employee_id');

        return $assignment ? (int) $assignment : null;
    }

    private function isValidMobile(?string $mobile): bool
    {
        if ($mobile === null || trim($mobile) === '') {
            return false;
        }

        $validator = validator(['mobile' => $mobile], [
            'mobile' => ['required', 'string', new ValidMobileNumber],
        ]);

        return ! $validator->fails();
    }

    private function buildEndpoint(WhatsAppSetting $settings): string
    {
        return $this->buildMessagesEndpoint($settings);
    }

    /**
     * @return list<array{type: string, parameters: list<array{type: string, text: string}>}>
     */
    private function buildTemplateComponents(array $parameters): array
    {
        return $this->buildTemplateComponentsPublic($parameters);
    }
}
