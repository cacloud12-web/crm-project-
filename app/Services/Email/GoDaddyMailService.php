<?php

namespace App\Services\Email;

use App\Models\CaMaster;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\EmailSetting;
use Illuminate\Support\Collection;

class GoDaddyMailService
{
    public const MAIL_HOST = 'MAIL_HOST';

    public const MAIL_PORT = 'MAIL_PORT';

    public const MAIL_USERNAME = 'MAIL_USERNAME';

    public const MAIL_PASSWORD = 'MAIL_PASSWORD';

    public const MAIL_ENCRYPTION = 'MAIL_ENCRYPTION';

    public const MAIL_FROM_ADDRESS = 'MAIL_FROM_ADDRESS';

    public const MAIL_FROM_NAME = 'MAIL_FROM_NAME';

    public const TEMPLATE_VARIABLES = [
        '{{name}}' => 'ca_name',
        '{{firm_name}}' => 'firm_name',
        '{{city}}' => 'city.city_name',
        '{{state}}' => 'state.state_name',
        '{{mobile}}' => 'mobile_no',
        '{{email}}' => 'email_id',
    ];

    public function __construct(
        private readonly EmailSettingsService $emailSettingsService,
    ) {}

    /**
     * Map GoDaddy SMTP config to a Laravel-compatible mail transport array.
     *
     * @return array<string, mixed>
     */
    public function buildMailTransport(EmailSetting $settings): array
    {
        return [
            self::MAIL_HOST => $settings->smtp_host,
            self::MAIL_PORT => $settings->smtp_port,
            self::MAIL_USERNAME => $settings->smtp_username,
            self::MAIL_PASSWORD => '[REDACTED]',
            self::MAIL_ENCRYPTION => $settings->smtp_encryption,
            self::MAIL_FROM_ADDRESS => $settings->from_email,
            self::MAIL_FROM_NAME => $settings->from_name,
        ];
    }

    /**
     * Build the outbound mail object for a single lead (mapping only).
     *
     * @return array{
     *     to: string,
     *     subject: string,
     *     body: string,
     *     from_email: ?string,
     *     from_name: ?string,
     *     transport: array<string, mixed>
     * }
     */
    public function buildMailObject(
        EmailSetting $settings,
        string $recipientEmail,
        string $subject,
        string $body,
    ): array {
        return [
            'to' => $recipientEmail,
            'subject' => trim($subject),
            'body' => $body,
            'from_email' => $settings->from_email,
            'from_name' => $settings->from_name,
            'transport' => $this->buildMailTransport($settings),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function validateDispatchPrerequisites(
        ?EmailSetting $settings,
        ?string $recipientEmail,
        string $subject,
        string $body,
    ): array {
        $errors = [];

        if (! $settings) {
            return ['Email settings record is missing.'];
        }

        if (! filled($settings->smtp_host)) {
            $errors[] = 'SMTP host is not configured.';
        }

        if (! filled($settings->smtp_port)) {
            $errors[] = 'SMTP port is not configured.';
        }

        if (! filled($settings->smtp_username)) {
            $errors[] = 'SMTP username is not configured.';
        }

        if (! $settings->hasPassword()) {
            $errors[] = 'SMTP password is not configured.';
        }

        if (! filled($settings->smtp_encryption)) {
            $errors[] = 'SMTP encryption is not configured.';
        }

        if (! filled($settings->from_email)) {
            $errors[] = 'From email address is not configured.';
        }

        if (! filled(trim($subject))) {
            $errors[] = 'Email subject is required.';
        }

        if (! filled(trim(strip_tags($body)))) {
            $errors[] = 'Email message body is required.';
        }

        $email = trim((string) $recipientEmail);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Lead email address is missing or invalid.';
        }

        return $errors;
    }

    /**
     * @return array{
     *     valid: bool,
     *     errors: array<int, string>,
     *     mail_object: ?array,
     *     provider_response: ?string
     * }
     */
    public function prepareForLead(
        CaMaster $lead,
        string $subject,
        string $body,
        ?EmailSetting $settings = null,
    ): array {
        $settings ??= $this->emailSettingsService->current();
        $recipient = (string) $lead->email_id;
        $errors = $this->validateDispatchPrerequisites($settings, $recipient, $subject, $body);

        if ($errors !== []) {
            return [
                'valid' => false,
                'errors' => $errors,
                'mail_object' => null,
                'provider_response' => null,
            ];
        }

        $mailObject = $this->buildMailObject($settings, $recipient, $subject, $body);

        return [
            'valid' => true,
            'errors' => [],
            'mail_object' => $mailObject,
            'provider_response' => json_encode([
                'provider' => $settings->provider_name,
                'mode' => $settings->mode,
                'mapped_mail' => $mailObject,
                'dispatch' => 'mapped_not_sent',
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildCampaignMailObjects(EmailCampaign $campaign, Collection $leads, ?EmailSetting $settings = null): array
    {
        $settings ??= $this->emailSettingsService->current();

        return $leads->map(function (CaMaster $lead) use ($campaign, $settings) {
            $subject = $this->renderTemplate((string) $campaign->subject, $lead);
            $body = $this->renderTemplate((string) $campaign->body_template, $lead);
            $prepared = $this->prepareForLead($lead, $subject, $body, $settings);

            return [
                'ca_id' => (int) $lead->ca_id,
                'lead_id' => (int) $lead->ca_id,
                'firm_name' => $lead->firm_name,
                'recipient_email' => $lead->email_id,
                'subject' => $subject,
                'message' => $body,
                'valid' => $prepared['valid'],
                'errors' => $prepared['errors'],
                'mail_object' => $prepared['mail_object'],
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function mapProviderResponseToLogAttributes(array $providerResponse): array
    {
        $success = (bool) ($providerResponse['success'] ?? $providerResponse['sent'] ?? false);

        return [
            'email_status' => $success ? 'Delivered' : 'Failed',
            'provider_response' => json_encode($providerResponse),
            'error_message' => $success
                ? null
                : (string) ($providerResponse['message'] ?? $providerResponse['error'] ?? 'Email provider returned failure'),
            'sent_at' => now(),
        ];
    }

    public function renderTemplate(string $template, CaMaster $lead): string
    {
        $lead->loadMissing(['city', 'state']);

        $replacements = [
            '{{name}}' => $lead->ca_name ?? '',
            '{{firm_name}}' => $lead->firm_name ?? '',
            '{{city}}' => $lead->city?->city_name ?? '',
            '{{state}}' => $lead->state?->state_name ?? '',
            '{{mobile}}' => $lead->mobile_no ?? '',
            '{{email}}' => $lead->email_id ?? '',
        ];

        return strtr($template, $replacements);
    }

    public function renderEmailTemplate(string $subject, string $body, CaMaster $lead): array
    {
        return [
            'subject' => $this->renderTemplate($subject, $lead),
            'body' => $this->renderTemplate($body, $lead),
        ];
    }

    /**
     * Queue-phase mapping stub — validates and stores mapped mail object, does not send SMTP.
     */
    public function prepareQueuedDispatch(EmailLog $log): EmailLog
    {
        $settings = $this->emailSettingsService->current();
        $prepared = $this->prepareForLead(
            $log->caMaster()->firstOrFail(),
            (string) $log->subject,
            (string) $log->body,
            $settings,
        );

        if (! $prepared['valid']) {
            $error = implode('; ', $prepared['errors']);
            $log->update([
                'email_status' => 'Failed',
                'error_message' => $error,
                'failed_reason' => $error,
            ]);

            return $log->fresh();
        }

        $log->update([
            'email_status' => 'Queued',
            'provider_response' => $prepared['provider_response'],
            'queued_at' => $log->queued_at ?? now(),
        ]);

        return $log->fresh();
    }
}
