<?php

namespace App\Services\Sms;

use App\Models\CaMaster;
use App\Models\SmsCampaign;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SmsAlertMappingService
{
    public const API_FIELD_API_KEY = 'apikey';

    public const API_FIELD_SENDER = 'sender';

    public const API_FIELD_MOBILE = 'mobileno';

    public const API_FIELD_TEXT = 'text';

    public const ERROR_MOBILE_REQUIRED = 'Mobile Number is required before sending SMS. Please update the lead first.';

    public const ERROR_MOBILE_INVALID = 'Mobile Number must be at least 10 digits. Please update the lead first.';

    public const ERROR_MESSAGE_REQUIRED = 'Message template is required.';

    public function __construct(
        private readonly SmsSettingsService $smsSettingsService,
    ) {}

    /**
     * Map SMS Alert push.json request fields from CRM records.
     *
     * @return array{apikey:?string,sender:?string,mobileno:string,text:string}
     */
    public function buildPushPayload(SmsSetting $settings, string $mobileNo, string $text): array
    {
        return [
            self::API_FIELD_API_KEY => $settings->api_key,
            self::API_FIELD_SENDER => $settings->sender_id,
            self::API_FIELD_MOBILE => $this->normalizeMobile($mobileNo),
            self::API_FIELD_TEXT => trim($text),
        ];
    }

    /**
     * Validate SMS settings, lead mobile, and message before any future dispatch.
     *
     * @return array<int, string>
     */
    public function validateDispatchPrerequisites(?SmsSetting $settings, ?string $mobileNo, string $message): array
    {
        $errors = [];

        if (! $settings) {
            $errors[] = 'SMS settings record is missing.';

            return $errors;
        }

        if (! filled($settings->api_url)) {
            $errors[] = 'SMS API URL is not configured.';
        }

        if (! $settings->hasApiKey()) {
            $errors[] = 'SMS API Key is not configured.';
        }

        if (! filled($settings->sender_id)) {
            $errors[] = 'SMS Sender ID is not configured.';
        }

        if (! filled(trim($message))) {
            $errors[] = self::ERROR_MESSAGE_REQUIRED;
        }

        $mobileError = $this->leadMobileValidationError($mobileNo);
        if ($mobileError !== null) {
            $errors[] = $mobileError;
        }

        return $errors;
    }

    public function leadMobileValidationError(?string $mobileNo): ?string
    {
        $normalizedMobile = $this->normalizeMobile($mobileNo ?? '');
        if ($normalizedMobile === '') {
            return self::ERROR_MOBILE_REQUIRED;
        }

        if (strlen($normalizedMobile) < 10) {
            return self::ERROR_MOBILE_INVALID;
        }

        return null;
    }

    public function isValidLeadMobile(?string $mobileNo): bool
    {
        return $this->leadMobileValidationError($mobileNo) === null;
    }

    /**
     * Prepare mapped payload for a single lead without sending HTTP request.
     *
     * @return array{
     *     valid: bool,
     *     errors: array<int, string>,
     *     payload: array{apikey:?string,sender:?string,mobileno:string,text:string}|null,
     *     provider_response: ?string
     * }
     */
    public function prepareForLead(CaMaster $lead, string $message, ?SmsSetting $settings = null): array
    {
        $settings ??= $this->smsSettingsService->current();
        $errors = $this->validateDispatchPrerequisites($settings, $lead->mobile_no, $message);

        if ($errors !== []) {
            return [
                'valid' => false,
                'errors' => $errors,
                'payload' => null,
                'provider_response' => null,
            ];
        }

        $payload = $this->buildPushPayload($settings, (string) $lead->mobile_no, $message);

        return [
            'valid' => true,
            'errors' => [],
            'payload' => $payload,
            'provider_response' => json_encode([
                'provider' => $settings->provider_name,
                'api_url' => $settings->api_url,
                'mode' => $settings->mode,
                'mapped_payload' => $this->maskPayloadForDisplay($payload),
                'dispatch' => 'mapped_not_sent',
            ]),
        ];
    }

    /**
     * Build campaign-level payload mapping for all selected leads.
     *
     * @return array<int, array{
     *     ca_id: int,
     *     lead_id: int,
     *     firm_name: ?string,
     *     mobile_no: ?string,
     *     message: string,
     *     valid: bool,
     *     errors: array<int, string>,
     *     api_payload: ?array
     * }>
     */
    public function buildCampaignPayloads(SmsCampaign $campaign, Collection $leads, ?SmsSetting $settings = null): array
    {
        $settings ??= $this->smsSettingsService->current();
        $template = (string) $campaign->message_template;

        return $leads->map(function (CaMaster $lead) use ($settings, $template) {
            $message = $this->renderMessage($template, $lead);
            $prepared = $this->prepareForLead($lead, $message, $settings);

            return [
                'ca_id' => (int) $lead->ca_id,
                'lead_id' => (int) $lead->ca_id,
                'firm_name' => $lead->firm_name,
                'mobile_no' => $lead->mobile_no,
                'message' => $message,
                'valid' => $prepared['valid'],
                'errors' => $prepared['errors'],
                'api_payload' => $prepared['payload'],
            ];
        })->values()->all();
    }

    /**
     * Map a future SMS Alert API response into sms_logs columns.
     *
     * @return array{
     *     sms_status: string,
     *     provider_response: ?string,
     *     error_message: ?string,
     *     sent_at: ?Carbon
     * }
     */
    public function mapProviderResponseToLogAttributes(array $providerResponse): array
    {
        $status = strtolower((string) ($providerResponse['status'] ?? $providerResponse['result'] ?? ''));
        $success = in_array($status, ['success', 'ok', 'sent', 'delivered'], true);

        return [
            'sms_status' => $success ? 'Delivered' : 'Failed',
            'provider_response' => json_encode($providerResponse),
            'error_message' => $success
                ? null
                : (string) ($providerResponse['message'] ?? $providerResponse['error'] ?? 'SMS provider returned failure'),
            'sent_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapLogRecord(SmsLog $log): array
    {
        return [
            'campaign_id' => $log->campaign_id,
            'lead_id' => $log->ca_id,
            'employee_id' => $log->employee_id,
            'mobile_no' => $log->mobile_no,
            'message' => $log->message,
            'status' => $log->sms_status,
            'provider_response' => $log->provider_response,
            'error_message' => $log->error_message ?? $log->failed_reason,
            'sent_at' => $log->sent_at,
        ];
    }

    public const TEMPLATE_VARIABLES = [
        '{{name}}' => 'ca_name',
        '{{firm_name}}' => 'firm_name',
        '{{city}}' => 'city.city_name',
        '{{state}}' => 'state.state_name',
        '{{mobile}}' => 'mobile_no',
    ];

    /**
     * Mask sensitive fields for API/UI preview display.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function maskPayloadForDisplay(array $payload): array
    {
        return [
            self::API_FIELD_API_KEY => filled($payload[self::API_FIELD_API_KEY] ?? null) ? '******' : null,
            self::API_FIELD_SENDER => filled($payload[self::API_FIELD_SENDER] ?? null) ? '******' : null,
            self::API_FIELD_MOBILE => $payload[self::API_FIELD_MOBILE] ?? '',
            self::API_FIELD_TEXT => $payload[self::API_FIELD_TEXT] ?? '',
        ];
    }

    /**
     * @param  Collection<int, CaMaster>  $leads
     * @return Collection<int, CaMaster>
     */
    public function deduplicateLeadsByMobile(Collection $leads): Collection
    {
        $seen = [];

        return $leads->filter(function (CaMaster $lead) use (&$seen) {
            $mobile = $this->normalizeMobile((string) $lead->mobile_no);
            if ($mobile === '') {
                return true;
            }
            if (isset($seen[$mobile])) {
                return false;
            }
            $seen[$mobile] = true;

            return true;
        })->values();
    }

    public function calculateCharacterCount(string $message): int
    {
        return mb_strlen($message);
    }

    public function calculateSmsCount(string $message): int
    {
        $length = $this->calculateCharacterCount($message);

        if ($length === 0) {
            return 0;
        }

        return (int) ceil($length / 160);
    }

    /**
     * Validate campaign preparation prerequisites before payload generation.
     *
     * @param  Collection<int, CaMaster>  $leads
     * @return array{valid: bool, errors: array<int, string>, warnings: array<int, string>}
     */
    public function validateCampaignPreparation(?SmsSetting $settings, Collection $leads, string $messageTemplate): array
    {
        $errors = [];
        $warnings = [];

        if (! $settings) {
            $errors[] = 'SMS settings are not configured.';

            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        if (! $settings->is_active) {
            $errors[] = 'SMS provider is inactive.';
        }

        if (! filled($settings->api_url)) {
            $errors[] = 'SMS API URL is not configured.';
        }

        if (! $settings->hasApiKey()) {
            $errors[] = 'SMS API Key is not configured.';
        }

        if (! filled($settings->sender_id)) {
            $errors[] = 'SMS Sender ID is not configured.';
        }

        if (! filled(trim($messageTemplate))) {
            $errors[] = self::ERROR_MESSAGE_REQUIRED;
        }

        if ($leads->isEmpty()) {
            $errors[] = 'At least one lead must be selected.';
        }

        $deduped = $this->deduplicateLeadsByMobile($leads);
        $duplicateCount = $leads->count() - $deduped->count();
        if ($duplicateCount > 0) {
            $warnings[] = $duplicateCount.' duplicate mobile number(s) will be skipped.';
        }

        $leadsMissingMobile = $deduped->filter(function (CaMaster $lead) {
            return $this->leadMobileValidationError($lead->mobile_no) !== null;
        });

        if ($leadsMissingMobile->isNotEmpty()) {
            if ($leadsMissingMobile->count() === 1) {
                $errors[] = self::ERROR_MOBILE_REQUIRED;
            } else {
                $errors[] = $leadsMissingMobile->count().' lead(s) are missing a valid mobile number. Please update the lead(s) first.';
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    public function renderMessage(string $template, CaMaster $lead): string
    {
        $lead->loadMissing(['city', 'state', 'sourceLead']);

        $replacements = [
            '{{name}}' => $lead->ca_name ?? '',
            '{{firm_name}}' => $lead->firm_name ?? '',
            '{{mobile}}' => $lead->mobile_no ?? '',
            '{{city}}' => $lead->city?->city_name ?? '',
            '{{state}}' => $lead->state?->state_name ?? '',
            '{{source}}' => $lead->sourceLead?->source_name ?? '',
            '{{rating}}' => (string) ($lead->rating ?? ''),
            '{{team_size}}' => (string) ($lead->team_size ?? ''),
            '{{existing_software}}' => (string) ($lead->existing_software ?? ''),
        ];

        return strtr($template, $replacements);
    }

    private function normalizeMobile(string $mobileNo): string
    {
        $digits = preg_replace('/\D/', '', $mobileNo) ?? '';

        if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
            $digits = substr($digits, -10);
        }

        return $digits;
    }
}
