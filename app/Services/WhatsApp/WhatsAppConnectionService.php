<?php

namespace App\Services\WhatsApp;

use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\Activity\ActivityLogService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class WhatsAppConnectionService
{
    public function __construct(
        private readonly WhatsAppSettingsService $settingsService,
        private readonly WhatsAppCloudMappingService $mappingService,
        private readonly WhatsAppDispatchService $dispatchService,
        private readonly WhatsAppLogService $logService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * Verify credentials against Meta Graph API without sending a customer message.
     *
     * @return array{success: bool, message: string, provider_response: array<string, mixed>, settings: array<string, mixed>}
     */
    public function testConnection(WhatsAppSetting $settings, User $user): array
    {
        $this->settingsService->ensureCanManageSettings($user);

        $localValidation = $this->settingsService->validateConfiguration($user);
        if (! $localValidation['valid']) {
            throw ValidationException::withMessages([
                'configuration' => $localValidation['errors'],
            ]);
        }

        $token = (string) $settings->access_token;
        $version = (string) $settings->api_version;
        $baseUrl = rtrim((string) config('whatsapp_cloud.graph_base_url'), '/');

        try {
            $phoneResponse = Http::timeout(30)
                ->withToken($token)
                ->acceptJson()
                ->get("{$baseUrl}/{$version}/{$settings->phone_number_id}", [
                    'fields' => 'verified_name,display_phone_number',
                ]);

            $businessResponse = Http::timeout(30)
                ->withToken($token)
                ->acceptJson()
                ->get("{$baseUrl}/{$version}/{$settings->business_account_id}", [
                    'fields' => 'name',
                ]);

            $phoneBody = $this->normalizeResponseBody($phoneResponse->json(), $phoneResponse->status(), $phoneResponse->body());
            $businessBody = $this->normalizeResponseBody($businessResponse->json(), $businessResponse->status(), $businessResponse->body());

            $providerResponse = [
                'phone_number' => $phoneBody,
                'business_account' => $businessBody,
            ];

            if ($phoneResponse->successful() && $businessResponse->successful() && ! isset($phoneBody['error']) && ! isset($businessBody['error'])) {
                return $this->recordSuccessfulTest($settings, $providerResponse, $user);
            }

            $errorMessage = $this->extractErrorMessage($phoneBody)
                ?: $this->extractErrorMessage($businessBody)
                ?: 'Meta WhatsApp Cloud API credential verification failed.';

            return $this->recordFailedTest($settings, $providerResponse, $user, $errorMessage);
        } catch (\Throwable $exception) {
            return $this->recordFailedTest(
                $settings,
                ['status' => 'failed', 'error' => $exception->getMessage()],
                $user,
                'WhatsApp connection failed: '.$exception->getMessage(),
            );
        }
    }

    /**
     * Send one approved template to a configured test mobile number (live mode only).
     *
     * @return array{success: bool, message: string, provider_response: array<string, mixed>, meta_message_id: ?string, log_id: ?int, request_body: array<string, mixed>}
     */
    public function sendTestTemplate(
        WhatsAppSetting $settings,
        MessageTemplate $template,
        string $mobileNo,
        User $user,
    ): array {
        $this->settingsService->ensureCanManageSettings($user);
        $this->settingsService->assertReadyForLiveDispatch($settings);

        if (! $template->isApproved()) {
            throw ValidationException::withMessages([
                'message_template_id' => ['Only approved templates can be sent.'],
            ]);
        }

        $normalized = $this->mappingService->normalizeRecipientMobile($mobileNo);
        if (! $normalized) {
            throw ValidationException::withMessages([
                'mobile_no' => ['A valid test mobile number is required.'],
            ]);
        }

        $payload = $this->mappingService->buildTestTemplatePayload($template, $mobileNo, $settings);
        $result = $this->dispatchService->send($settings, $payload);
        $log = $this->logService->storeTestTemplateSend($template, $normalized, $payload, $result);
        $requestBody = $payload['request_body'] ?? [];

        if ($result['success']) {
            $this->settingsService->recordSuccessfulSend($settings);

            $this->activityLogService->log(
                'WHATSAPP_SETTINGS',
                'WhatsApp Test Template Sent',
                (string) $settings->id,
                $template->template_name.' sent to test mobile',
                $user->name ?? $user->email ?? 'System',
                afterValue: array_merge($result['provider_response'], ['request' => $requestBody]),
            );

            return [
                'success' => true,
                'message' => 'Test template sent successfully.',
                'provider_response' => array_merge($result['provider_response'], ['request' => $requestBody]),
                'meta_message_id' => $result['meta_message_id'],
                'log_id' => $log->id,
                'request_body' => $requestBody,
            ];
        }

        $errorMessage = $result['error_message'] ?? 'Failed to send test template.';

        $this->activityLogService->log(
            'WHATSAPP_SETTINGS',
            'WhatsApp Test Template Failed',
            (string) $settings->id,
            $errorMessage,
            $user->name ?? $user->email ?? 'System',
            afterValue: array_merge($result['provider_response'], ['request' => $requestBody]),
        );

        return [
            'success' => false,
            'message' => $errorMessage,
            'provider_response' => array_merge($result['provider_response'], ['request' => $requestBody]),
            'meta_message_id' => null,
            'log_id' => $log->id,
            'request_body' => $requestBody,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function normalizeResponseBody(?array $body, int $httpStatus, string $rawBody): array
    {
        if (! is_array($body)) {
            return [
                'http_status' => $httpStatus,
                'raw' => $rawBody,
            ];
        }

        $body['http_status'] = $httpStatus;

        return $body;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function extractErrorMessage(array $providerResponse): ?string
    {
        if (isset($providerResponse['error']) && is_array($providerResponse['error'])) {
            $error = $providerResponse['error'];
            $message = (string) ($error['message'] ?? '');
            $code = $error['code'] ?? null;
            $subcode = $error['error_subcode'] ?? null;

            if ($message !== '') {
                return trim($message.($code ? " (code: {$code})" : '').($subcode ? " (subcode: {$subcode})" : ''));
            }
        }

        if (isset($providerResponse['http_status']) && (int) $providerResponse['http_status'] >= 400) {
            return (string) ($providerResponse['message'] ?? $providerResponse['raw'] ?? 'Graph API request failed.');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @return array{success: bool, message: string, provider_response: array<string, mixed>, settings: array<string, mixed>}
     */
    private function recordSuccessfulTest(WhatsAppSetting $settings, array $providerResponse, User $user): array
    {
        $settings->update([
            'integration_status' => WhatsAppSetting::INTEGRATION_INTEGRATED,
            'last_tested_at' => now(),
            'last_test_status' => 'success',
            'last_test_response' => json_encode($providerResponse, JSON_UNESCAPED_UNICODE),
        ]);

        $this->activityLogService->log(
            'WHATSAPP_SETTINGS',
            'WhatsApp Test Successful',
            (string) $settings->id,
            'Meta WhatsApp Cloud API connection test succeeded',
            $user->name ?? $user->email ?? 'System',
            afterValue: $providerResponse,
        );

        return [
            'success' => true,
            'message' => 'WhatsApp Cloud API connection test succeeded.',
            'provider_response' => $providerResponse,
            'settings' => $this->settingsService->toPublicArray($settings->fresh()),
        ];
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @return array{success: bool, message: string, provider_response: array<string, mixed>, settings: array<string, mixed>}
     */
    private function recordFailedTest(
        WhatsAppSetting $settings,
        array $providerResponse,
        User $user,
        string $message,
    ): array {
        $settings->update([
            'integration_status' => WhatsAppSetting::INTEGRATION_FAILED,
            'last_tested_at' => now(),
            'last_test_status' => 'failed',
            'last_test_response' => json_encode($providerResponse, JSON_UNESCAPED_UNICODE),
        ]);

        $this->activityLogService->log(
            'WHATSAPP_SETTINGS',
            'WhatsApp Test Failed',
            (string) $settings->id,
            $message,
            $user->name ?? $user->email ?? 'System',
            afterValue: $providerResponse,
        );

        return [
            'success' => false,
            'message' => $message,
            'provider_response' => $providerResponse,
            'settings' => $this->settingsService->toPublicArray($settings->fresh()),
        ];
    }
}
