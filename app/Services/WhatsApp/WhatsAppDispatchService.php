<?php

namespace App\Services\WhatsApp;

use App\Models\WaMessageLog;
use App\Models\WaMessageLogStatus;
use App\Models\WhatsAppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppDispatchService
{
    public function __construct(
        private readonly WhatsAppCloudMappingService $mappingService,
    ) {}

    /**
     * @param  array<string, mixed>  $apiPayload
     * @return array{success: bool, status: string, meta_message_id: ?string, provider_response: array<string, mixed>, error_message: ?string}
     */
    public function send(WhatsAppSetting $settings, array $apiPayload): array
    {
        $requestBody = $apiPayload['request_body'] ?? $apiPayload;
        $endpoint = (string) ($apiPayload['endpoint'] ?? $this->mappingService->buildMessagesEndpoint($settings));

        Log::info('whatsapp.dispatch.request', [
            'endpoint' => $endpoint,
            'to' => $requestBody['to'] ?? null,
            'template' => $requestBody['template']['name'] ?? null,
            'request_body' => $requestBody,
        ]);

        try {
            $response = Http::timeout(30)
                ->withToken((string) $settings->access_token)
                ->acceptJson()
                ->post($endpoint, $requestBody);

            $providerResponse = $response->json();
            if (! is_array($providerResponse)) {
                $providerResponse = [
                    'http_status' => $response->status(),
                    'raw' => $response->body(),
                ];
            } else {
                $providerResponse['http_status'] = $response->status();
            }
            $providerResponse['endpoint'] = $endpoint;

            Log::info('whatsapp.dispatch.response', [
                'endpoint' => $endpoint,
                'http_status' => $response->status(),
                'success' => $this->isSuccessResponse($providerResponse) && $response->successful(),
                'provider_response' => $providerResponse,
            ]);
        } catch (\Throwable $exception) {
            Log::error('whatsapp.dispatch.exception', [
                'endpoint' => $endpoint,
                'message' => $exception->getMessage(),
                'request_body' => $requestBody,
            ]);
            return [
                'success' => false,
                'status' => WaMessageLogStatus::API_ERROR,
                'meta_message_id' => null,
                'provider_response' => [
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                    'endpoint' => $endpoint,
                    'request' => $requestBody,
                ],
                'error_message' => $exception->getMessage(),
            ];
        }

        if ($this->isSuccessResponse($providerResponse) && $response->successful()) {
            return [
                'success' => true,
                'status' => WaMessageLogStatus::SENT,
                'meta_message_id' => $this->extractMessageId($providerResponse),
                'provider_response' => $this->attachRequestToResponse($this->sanitizeProviderResponse($providerResponse), $requestBody),
                'error_message' => null,
            ];
        }

        return [
            'success' => false,
            'status' => $this->resolveFailureStatus($providerResponse, $response->status()),
            'meta_message_id' => null,
            'provider_response' => $this->attachRequestToResponse($this->sanitizeProviderResponse($providerResponse), $requestBody),
            'error_message' => $this->extractErrorMessage($providerResponse, $response->status()),
        ];
    }

    /**
     * @param  array{success: bool, status: string, meta_message_id: ?string, provider_response: array<string, mixed>, error_message: ?string}  $result
     */
    public function applyDispatchResult(WaMessageLog $log, array $result): WaMessageLog
    {
        $log->update([
            'message_status' => $result['status'],
            'meta_message_id' => $result['meta_message_id'],
            'provider_response' => $result['provider_response'],
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
        if (isset($providerResponse['error'])) {
            return false;
        }

        if (isset($providerResponse['http_status']) && (int) $providerResponse['http_status'] >= 400) {
            return false;
        }

        return isset($providerResponse['messages'][0]['id']);
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function extractMessageId(array $providerResponse): ?string
    {
        $id = $providerResponse['messages'][0]['id'] ?? null;

        return $id ? (string) $id : null;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function extractErrorMessage(array $providerResponse, int $httpStatus): string
    {
        return MetaWhatsAppErrorMapper::map($providerResponse, $httpStatus);
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     */
    private function resolveFailureStatus(array $providerResponse, int $httpStatus): string
    {
        if ($httpStatus === 429) {
            return WaMessageLogStatus::API_ERROR;
        }

        $code = $providerResponse['error']['code'] ?? null;
        if (in_array((int) $code, [190, 100, 10, 200, 131030], true)) {
            return WaMessageLogStatus::API_ERROR;
        }

        return WaMessageLogStatus::FAILED;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @return array<string, mixed>
     */
    private function sanitizeProviderResponse(array $providerResponse): array
    {
        unset($providerResponse['access_token']);

        return $providerResponse;
    }

    /**
     * @param  array<string, mixed>  $providerResponse
     * @param  array<string, mixed>  $requestBody
     * @return array<string, mixed>
     */
    private function attachRequestToResponse(array $providerResponse, array $requestBody): array
    {
        if ($requestBody !== []) {
            $providerResponse['request'] = $requestBody;
        }

        return $providerResponse;
    }
}
