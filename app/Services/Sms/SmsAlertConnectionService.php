<?php

namespace App\Services\Sms;

use App\Models\CaMaster;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class SmsAlertConnectionService
{
    public function __construct(
        private readonly SmsAlertMappingService $mappingService,
        private readonly SmsSettingsService $settingsService,
        private readonly SmsDltTemplateService $smsDltTemplateService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * @return array{success: bool, message: string, provider_response: array<string, mixed>, settings: array<string, mixed>}
     */
    public function testConnection(SmsSetting $settings, string $mobileNo, string $text, User $user): array
    {
        $this->settingsService->ensureCanManageSettings($user);

        $localValidation = $this->settingsService->validateConfiguration($user);
        if (! $localValidation['valid']) {
            throw ValidationException::withMessages([
                'configuration' => $localValidation['errors'],
            ]);
        }

        $mobileError = $this->mappingService->leadMobileValidationError($mobileNo);
        if ($mobileError !== null) {
            throw ValidationException::withMessages([
                'mobileno' => [$mobileError],
            ]);
        }

        if (! filled(trim($text))) {
            throw ValidationException::withMessages([
                'text' => ['Test message text is required.'],
            ]);
        }

        $text = $this->resolveTestMessage($settings, $text);

        $payload = $this->mappingService->buildPushPayload(
            $settings,
            $mobileNo,
            $text,
            $settings->dlt_template_id,
        );

        try {
            $response = Http::timeout(30)
                ->asForm()
                ->post((string) $settings->api_url, $payload);

            $providerResponse = $response->json();
            if (! is_array($providerResponse)) {
                $providerResponse = [
                    'status' => $response->successful() ? 'success' : 'failed',
                    'http_status' => $response->status(),
                    'raw' => $response->body(),
                ];
            } else {
                $providerResponse['http_status'] = $response->status();
            }
        } catch (\Throwable $exception) {
            return $this->recordFailedTest(
                $settings,
                ['status' => 'failed', 'error' => $exception->getMessage()],
                $user,
                'SMS Alert connection failed: '.$exception->getMessage(),
            );
        }

        if ($this->isSuccessResponse($providerResponse) && $response->successful()) {
            return $this->recordSuccessfulTest($settings, $providerResponse, $user);
        }

        $errorMessage = $this->extractErrorMessage($providerResponse);

        return $this->recordFailedTest($settings, $providerResponse, $user, $errorMessage);
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    public function isSuccessResponse(array $providerResponse): bool
    {
        $status = strtolower((string) ($providerResponse['status'] ?? $providerResponse['result'] ?? ''));

        if (in_array($status, ['success', 'ok', 'sent', 'delivered'], true)) {
            return true;
        }

        if (isset($providerResponse['http_status']) && (int) $providerResponse['http_status'] >= 400) {
            return false;
        }

        return isset($providerResponse['message_id']) || isset($providerResponse['msgid']);
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function extractErrorMessage(array $providerResponse): string
    {
        return (string) (
            $providerResponse['description']
            ?? $providerResponse['message']
            ?? $providerResponse['error']
            ?? $providerResponse['raw']
            ?? 'SMS Alert API returned an error.'
        );
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @return array{success: bool, message: string, provider_response: array<string, mixed>, settings: array<string, mixed>}
     */
    private function recordSuccessfulTest(SmsSetting $settings, array $providerResponse, User $user): array
    {
        $settings->update([
            'integration_status' => SmsSetting::INTEGRATION_INTEGRATED,
            'last_tested_at' => now(),
            'last_test_status' => 'success',
            'last_test_response' => json_encode($providerResponse, JSON_UNESCAPED_UNICODE),
        ]);

        $this->activityLogService->log(
            'SMS_SETTINGS',
            'SMS Test Successful',
            (string) $settings->id,
            'SMS Alert live connection test succeeded',
            $user->name ?? $user->email ?? 'System',
            afterValue: $this->sanitizeProviderResponseForLog($providerResponse),
        );

        return [
            'success' => true,
            'message' => 'SMS Alert connection test succeeded.',
            'provider_response' => $this->sanitizeProviderResponseForLog($providerResponse),
            'settings' => $this->settingsService->toPublicArray($settings->fresh()),
        ];
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @return array{success: bool, message: string, provider_response: array<string, mixed>, settings: array<string, mixed>}
     */
    private function recordFailedTest(
        SmsSetting $settings,
        array $providerResponse,
        User $user,
        string $message,
    ): array {
        $settings->update([
            'integration_status' => SmsSetting::INTEGRATION_FAILED,
            'last_tested_at' => now(),
            'last_test_status' => 'failed',
            'last_test_response' => json_encode($providerResponse, JSON_UNESCAPED_UNICODE),
        ]);

        $this->activityLogService->log(
            'SMS_SETTINGS',
            'SMS Test Failed',
            (string) $settings->id,
            $message,
            $user->name ?? $user->email ?? 'System',
            afterValue: $this->sanitizeProviderResponseForLog($providerResponse),
        );

        return [
            'success' => false,
            'message' => $message,
            'provider_response' => $this->sanitizeProviderResponseForLog($providerResponse),
            'settings' => $this->settingsService->toPublicArray($settings->fresh()),
        ];
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @return array<string, mixed>
     */
    private function sanitizeProviderResponseForLog(array $providerResponse): array
    {
        unset($providerResponse['apikey'], $providerResponse['api_key']);

        return $providerResponse;
    }

    private function resolveTestMessage(SmsSetting $settings, string $text): string
    {
        $trimmed = trim($text);

        if (! filled($settings->dlt_template_id)) {
            return $trimmed;
        }

        $lower = strtolower($trimmed);
        $isGenericTest = $lower === 'crm sms alert connection test'
            || $lower === 'test'
            || str_contains($lower, 'connection test');

        if (! $isGenericTest) {
            return $trimmed;
        }

        $template = SmsTemplate::query()
            ->where('status', SmsTemplate::STATUS_APPROVED)
            ->where('is_active', true)
            ->orderByRaw(
                'CASE WHEN dlt_template_id = ? THEN 0 WHEN dlt_template_id IS NULL THEN 1 ELSE 2 END',
                [$settings->dlt_template_id],
            )
            ->first();

        if (! $template) {
            return $trimmed;
        }

        $lead = CaMaster::query()
            ->whereNotNull('mobile_no')
            ->where('mobile_no', '!=', '')
            ->first();

        if ($lead) {
            return $this->smsDltTemplateService->renderBody($template, $lead);
        }

        $index = 0;
        $samples = ['Client', 'CA Cloud Desk'];

        return (string) preg_replace_callback(
            '/\{#var#\}/',
            function () use (&$index, $samples) {
                return $samples[$index++] ?? 'Test';
            },
            (string) $template->body_template,
        );
    }
}
