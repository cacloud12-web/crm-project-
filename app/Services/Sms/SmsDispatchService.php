<?php

namespace App\Services\Sms;

use App\Models\SmsLog;
use App\Models\SmsLogStatus;
use App\Models\SmsSetting;
use Illuminate\Support\Facades\Http;

class SmsDispatchService
{
    public function __construct(
        private readonly SmsAlertMappingService $mappingService,
    ) {}

    /**
     * @return array{success: bool, status: string, provider_response: array<string, mixed>, error_message: ?string}
     */
    public function send(
        SmsSetting $settings,
        string $senderId,
        string $mobileNo,
        string $text,
        ?string $dltTemplateId = null,
    ): array {
        $payload = $this->mappingService->buildPushPayload(
            $this->settingsWithSender($settings, $senderId),
            $mobileNo,
            $text,
            $dltTemplateId,
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
            return [
                'success' => false,
                'status' => SmsLogStatus::API_ERROR,
                'provider_response' => ['status' => 'failed', 'error' => $exception->getMessage()],
                'error_message' => $exception->getMessage(),
            ];
        }

        if ($this->isSuccessResponse($providerResponse) && $response->successful()) {
            return [
                'success' => true,
                'status' => SmsLogStatus::SENT,
                'provider_response' => $this->sanitizeProviderResponse($providerResponse),
                'error_message' => null,
            ];
        }

        $errorMessage = (string) (
            $providerResponse['description']
            ?? $providerResponse['message']
            ?? $providerResponse['error']
            ?? 'SMS Alert API returned an error.'
        );

        return [
            'success' => false,
            'status' => SmsLogStatus::API_ERROR,
            'provider_response' => $this->sanitizeProviderResponse($providerResponse),
            'error_message' => $errorMessage,
        ];
    }

    public function applyDispatchResult(SmsLog $log, array $result): SmsLog
    {
        $log->update([
            'sms_status' => $result['status'],
            'provider_response' => json_encode($result['provider_response'], JSON_UNESCAPED_UNICODE),
            'error_message' => $result['error_message'],
            'failed_reason' => $result['success'] ? null : $result['error_message'],
            'sent_at' => $result['success'] ? now() : null,
            'queued_at' => $log->queued_at ?? now(),
        ]);

        return $log->fresh();
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

    private function settingsWithSender(SmsSetting $settings, string $senderId): SmsSetting
    {
        $clone = $settings->replicate();
        $clone->sender_id = $senderId;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @return array<string, mixed>
     */
    private function sanitizeProviderResponse(array $providerResponse): array
    {
        unset($providerResponse['apikey'], $providerResponse['api_key']);

        return $providerResponse;
    }
}
