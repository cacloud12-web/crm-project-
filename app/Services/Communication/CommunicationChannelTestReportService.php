<?php

namespace App\Services\Communication;

use App\Models\CaMaster;
use App\Models\EmailTemplate;
use App\Models\MessageTemplate;
use App\Models\SmsSetting;
use App\Services\Email\EmailSettingsService;
use App\Services\Email\GoDaddyMailService;
use App\Services\Sms\SmsSettingsService;
use App\Services\WhatsApp\WhatsAppCloudMappingService;
use App\Services\WhatsApp\WhatsAppDispatchService;
use App\Services\WhatsApp\WhatsAppSettingsService;
use App\Services\WhatsApp\WhatsAppTemplateService;

class CommunicationChannelTestReportService
{
    public function __construct(
        private readonly WhatsAppSettingsService $whatsAppSettingsService,
        private readonly WhatsAppCloudMappingService $whatsAppMappingService,
        private readonly WhatsAppDispatchService $whatsAppDispatchService,
        private readonly EmailSettingsService $emailSettingsService,
        private readonly GoDaddyMailService $goDaddyMailService,
        private readonly SmsSettingsService $smsSettingsService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(bool $sendLive = false): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'whatsapp' => $this->whatsappSection($sendLive),
            'email' => $this->emailSection(),
            'sms' => $this->smsSection(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function whatsappSection(bool $sendLive): array
    {
        $settings = $this->whatsAppSettingsService->current();
        $config = [
            'provider_active' => (bool) $settings->is_active,
            'live_mode' => $settings->isLiveMode(),
            'phone_number_id' => filled($settings->phone_number_id),
            'business_account_id' => filled($settings->business_account_id),
            'access_token' => $settings->hasAccessToken(),
            'configured' => $settings->isConfigured(),
            'integration_status' => $this->whatsAppSettingsService->integrationStatus($settings),
        ];
        $configOk = $config['provider_active']
            && $config['live_mode']
            && $config['phone_number_id']
            && $config['business_account_id']
            && $config['access_token'];

        $template = MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'company_registration_docs')
            ->where('language_code', 'en')
            ->first();

        $lead = CaMaster::query()->whereNotNull('mobile_no')->orderByDesc('ca_id')->first();

        $section = [
            'configuration_status' => $configOk ? 'ok' : 'incomplete',
            'configuration' => $config,
            'template' => $template ? [
                'name' => $template->template_name,
                'language' => $template->language_code,
                'category' => $template->category,
                'exists_in_crm' => true,
            ] : ['exists_in_crm' => false],
            'api_request' => null,
            'api_response' => null,
            'delivery_status' => 'not_sent',
            'error_message' => null,
        ];

        if (! $template) {
            $section['error_message'] = 'Template company_registration_docs is not in CRM database.';

            return $section;
        }

        if (! $lead) {
            $section['error_message'] = 'No lead with a mobile number found for payload preview.';

            return $section;
        }

        $mappingErrors = $this->whatsAppMappingService->validateDispatchSettings($template, $settings);
        $leadErrors = $this->whatsAppMappingService->validateLeadRecipient($lead);
        if ($mappingErrors !== [] || $leadErrors !== []) {
            $section['configuration_status'] = 'incomplete';
            $section['error_message'] = implode(' ', array_merge($mappingErrors, $leadErrors));
        }

        $payload = $this->whatsAppMappingService->buildCloudPayload($lead, $template, $settings);
        $section['api_request'] = $payload['request_body'] ?? null;
        $section['rendered_message'] = $payload['rendered_message'] ?? null;
        $section['body_parameters'] = $payload['crm_mapping']['body_parameters'] ?? null;

        if (! $sendLive || ! $configOk || $mappingErrors !== [] || $leadErrors !== []) {
            $section['delivery_status'] = 'dry_run';

            return $section;
        }

        $result = $this->whatsAppDispatchService->send($settings, $payload);
        $section['api_response'] = $result['provider_response'] ?? null;
        $section['delivery_status'] = $result['success'] ? 'sent' : 'failed';
        $section['error_message'] = $result['error_message'] ?? null;
        $section['meta_message_id'] = $result['meta_message_id'] ?? null;

        return $section;
    }

    /**
     * @return array<string, mixed>
     */
    private function emailSection(): array
    {
        $settings = $this->emailSettingsService->current();
        $validation = $this->emailSettingsService->validateConfiguration($settings);
        $template = EmailTemplate::query()->where('slug', 'company-registration-docs')->where('is_active', true)->first();
        $lead = CaMaster::query()->whereNotNull('email_id')->orderByDesc('ca_id')->first();

        $section = [
            'configuration_status' => ($validation['valid'] ?? false) ? 'ok' : 'incomplete',
            'configuration' => [
                'provider_name' => $settings->provider_name,
                'smtp_host' => $settings->smtp_host,
                'smtp_port' => $settings->smtp_port,
                'smtp_encryption' => $settings->smtp_encryption,
                'from_email' => $settings->from_email,
                'from_name' => $settings->from_name,
                'live_mode' => $settings->isLiveMode(),
                'is_active' => (bool) $settings->is_active,
                'has_password' => $settings->hasPassword(),
            ],
            'template' => $template ? ['slug' => $template->slug, 'subject' => $template->subject] : null,
            'api_request' => null,
            'api_response' => null,
            'delivery_status' => 'not_sent',
            'error_message' => null,
        ];

        if (! ($validation['valid'] ?? false)) {
            $section['error_message'] = implode(' ', $validation['errors'] ?? []);

            return $section;
        }

        if (! $template || ! $lead) {
            $section['error_message'] = 'Email template or lead with email not found.';

            return $section;
        }

        $rendered = $this->goDaddyMailService->renderTemplate(
            $template->body,
            $lead,
            $settings->from_name ?? 'CA Cloud Desk',
        );
        $subject = $this->goDaddyMailService->renderTemplate(
            $template->subject,
            $lead,
            $settings->from_name ?? 'CA Cloud Desk',
        );

        $section['api_request'] = [
            'to' => $lead->email_id,
            'subject' => $subject,
            'body_preview' => mb_substr($rendered, 0, 500),
            'transport' => $this->goDaddyMailService->buildMailTransport($settings),
        ];
        $section['delivery_status'] = 'dry_run';

        return $section;
    }

    /**
     * @return array<string, mixed>
     */
    private function smsSection(): array
    {
        $settings = SmsSetting::query()->first();
        $integration = $settings ? $this->smsSettingsService->integrationStatus($settings) : 'not_configured';
        $ready = in_array($integration, [
            SmsSetting::INTEGRATION_INTEGRATED,
            SmsSetting::INTEGRATION_CONNECTED,
        ], true);

        return [
            'configuration_status' => $integration === SmsSetting::INTEGRATION_INTEGRATED ? 'ok' : $integration,
            'configuration' => $settings ? [
                'provider_name' => $settings->provider_name,
                'is_active' => (bool) $settings->is_active,
                'live_mode' => $settings->isLiveMode(),
                'has_api_key' => $settings->hasApiKey(),
                'dlt_template_id' => filled($settings->dlt_template_id),
            ] : null,
            'api_request' => null,
            'api_response' => null,
            'delivery_status' => $ready ? 'ready' : 'not_configured',
            'error_message' => $ready ? null : 'SMS provider is not fully integrated.',
        ];
    }
}
