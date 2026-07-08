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
use Illuminate\Validation\ValidationException;

class WhatsAppCloudMappingService
{
    public function __construct(
        private readonly WhatsAppSettingsService $settingsService,
        private readonly WhatsAppTemplateService $templateService,
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
        if ($demoFollowUp?->scheduled_date) {
            $scheduled = Carbon::parse($demoFollowUp->scheduled_date);
            $demoDate = $scheduled->format('d-F-Y');
            $demoTime = $scheduled->format('h:i A');
        } elseif ($demoFollowUp?->next_followup_date) {
            $scheduled = Carbon::parse($demoFollowUp->next_followup_date);
            $demoDate = $scheduled->format('d-F-Y');
            $demoTime = $scheduled->format('h:i A');
        }

        $taskName = trim((string) ($latestTask?->followup_type ?: $latestTask?->notes ?: ''));
        $taskDate = $demoDate !== '' ? $demoDate : now()->format('d-F-Y');
        $expectedCompletion = $demoDate !== '' ? $demoDate : now()->format('d-F-Y');

        return [
            '{{name}}' => (string) ($lead->ca_name ?? ''),
            '{{client_name}}' => (string) ($lead->ca_name ?? ''),
            '{{firm_name}}' => (string) ($lead->firm_name ?? ''),
            '{{mobile}}' => (string) ($lead->mobile_no ?? ''),
            '{{city}}' => (string) ($lead->city?->city_name ?? ''),
            '{{state}}' => (string) ($lead->state?->state_name ?? ''),
            '{{demo_date}}' => $demoDate,
            '{{demo_time}}' => $demoTime,
            '{{employee_name}}' => (string) ($assignment?->employee?->name ?? ''),
            '{{assigned_staff}}' => $assignedStaff !== '' ? $assignedStaff : ((string) ($assignment?->employee?->name ?? '') !== '' ? (string) $assignment?->employee?->name : 'Not assigned'),
            '{{task_name}}' => $taskName !== '' ? $taskName : 'Task',
            '{{task_status}}' => (string) ($latestTask?->status ?? 'Scheduled'),
            '{{task_date}}' => $taskDate,
            '{{expected_completion}}' => $expectedCompletion,
            '{{scheduled_date}}' => $taskDate,
            '{{scheduled_time}}' => $demoTime !== '' ? $demoTime : now()->format('h:i A'),
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
    ): array {
        $settings ??= $this->settingsService->current();
        $variables = $this->resolveTemplateVariables($template, $lead);
        $bodyText = $this->renderTemplateBody($template->body_template, $variables);
        $parameters = $this->sanitizeMetaBodyParameters(
            $this->extractBodyParameters($template->body_template, $variables),
            $template,
        );

        $recipient = $this->normalizeRecipientMobile($lead->mobile_no);

        $templatePayload = [
            'name' => $template->metaApiTemplateName(),
            'language' => [
                'code' => $template->metaApiLanguageCode(),
            ],
        ];

        $components = $this->buildMetaTemplateComponents($template, $parameters);
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
            $errors[] = 'Template '.$template->template_name.' requires a document header URL. Set WHATSAPP_TASK_DOCUMENT_URL in .env.';
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
        $recipient = $this->normalizeRecipientMobile($mobileNo);
        $variables = $this->resolveTemplateVariables($template);
        $bodyText = $this->renderTemplateBody($template->body_template, $variables);
        $parameters = $this->sanitizeMetaBodyParameters(
            $this->extractBodyParameters($template->body_template, $variables),
            $template,
        );

        $templatePayload = [
            'name' => $template->metaApiTemplateName(),
            'language' => ['code' => $template->metaApiLanguageCode()],
        ];

        $components = $this->buildMetaTemplateComponents($template, $parameters);
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
    public function buildMetaTemplateComponents(MessageTemplate $template, array $bodyParameters): array
    {
        $components = [];
        $headerComponent = $this->buildHeaderComponent($template);

        if ($headerComponent !== null) {
            $components[] = $headerComponent;
        }

        if ($bodyParameters !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $text) => ['type' => 'text', 'text' => $text],
                    $bodyParameters,
                ),
            ];
        }

        return $components;
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
    public function resolveTemplateVariables(MessageTemplate $template, ?CaMaster $lead = null): array
    {
        $leadVariables = $lead ? $this->resolveVariables($lead) : $this->baseDummyVariables();
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
                    default => $leadVariables[$namedKey] ?? '',
                };
            }
        }

        preg_match_all('/\{\{[^}]+\}\}/', (string) $template->body_template, $matches);
        foreach (array_unique($matches[0] ?? []) as $placeholder) {
            if (! array_key_exists($placeholder, $resolved)) {
                $resolved[$placeholder] = $leadVariables[$placeholder] ?? 'Test';
            }
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    public function extractBodyParameters(string $bodyTemplate, array $variables): array
    {
        preg_match_all('/\{\{[^}]+\}\}/', $bodyTemplate, $matches);
        $keys = array_values(array_unique($matches[0] ?? []));
        $parameters = [];

        foreach ($keys as $key) {
            $parameters[] = (string) ($variables[$key] ?? '');
        }

        return $parameters;
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
            preg_match_all('/\{\{[^}]+\}\}/', (string) $template->body_template, $matches);
            $placeholders = array_values(array_unique($matches[0] ?? []));
        }

        $variableMap = is_array($template?->variable_map) ? $template->variable_map : [];

        return array_map(function (string $value, int $index) use ($placeholders, $variableMap) {
            if (trim($value) !== '') {
                return trim($value);
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
        return [
            '{{name}}' => 'Test User',
            '{{firm_name}}' => 'Test Firm',
            '{{mobile}}' => '9876543210',
            '{{city}}' => 'Mumbai',
            '{{state}}' => 'Maharashtra',
            '{{demo_date}}' => now()->format('d M Y'),
            '{{demo_time}}' => now()->format('h:i A'),
            '{{employee_name}}' => 'CRM Test',
            '{{task_name}}' => 'Follow-up Call',
            '{{task_status}}' => 'Scheduled',
            '{{scheduled_date}}' => now()->format('d M Y'),
            '{{scheduled_time}}' => now()->format('h:i A'),
            '{{1}}' => 'Test User',
            '{{2}}' => 'LawSeva',
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
