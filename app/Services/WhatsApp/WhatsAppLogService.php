<?php

namespace App\Services\WhatsApp;

use App\Models\CaMaster;
use App\Models\MessageTemplate;
use App\Models\WaMessageLog;
use App\Models\WhatsAppCampaign;
use App\Services\Activity\ActivityLogService;
use App\Services\Communication\CommunicationEligibilityService;
use App\Models\WaMessageLogStatus;

class WhatsAppLogService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly WhatsAppCloudMappingService $mappingService,
        private readonly CommunicationEligibilityService $eligibilityService,
    ) {}

    /**
     * Store mapped payload for a campaign recipient (no API call).
     */
    public function storeMappedPayload(
        WhatsAppCampaign $campaign,
        CaMaster $lead,
        MessageTemplate $template,
        bool $eligible = true,
        ?string $skipReason = null,
    ): WaMessageLog {
        $payload = $this->mappingService->buildCloudPayload($lead, $template);
        $employeeId = $this->mappingService->resolveEmployeeId($lead);
        $status = $eligible
            ? config('whatsapp_cloud.log_statuses.pending', WaMessageLogStatus::PENDING)
            : config('whatsapp_cloud.log_statuses.skipped', 'Skipped');

        return WaMessageLog::query()->create([
            'campaign_id' => $campaign->id,
            'ca_id' => $lead->ca_id,
            'employee_id' => $employeeId,
            'mobile_no' => $lead->mobile_no,
            'template_name' => $template->template_name,
            'language_code' => $template->language_code,
            'message' => $payload['rendered_message'] ?? '',
            'api_payload' => $payload,
            'message_status' => $status,
            'queued_at' => now(),
            'failed_reason' => $skipReason,
            'error_message' => $skipReason,
        ]);
    }

    /**
     * Map a future Meta API response into log columns (no HTTP).
     *
     * @param  array<string, mixed>  $providerResponse
     */
    public function applyProviderResponse(WaMessageLog $log, array $providerResponse): WaMessageLog
    {
        $messages = $providerResponse['messages'][0] ?? [];
        $status = strtolower((string) ($providerResponse['status'] ?? 'sent'));
        $metaMessageId = isset($messages['id']) ? (string) $messages['id'] : $log->meta_message_id;

        $mappedStatus = match ($status) {
            'delivered' => WaMessageLogStatus::DELIVERED,
            'read' => WaMessageLogStatus::READ,
            'failed' => WaMessageLogStatus::FAILED,
            'sent' => WaMessageLogStatus::SENT,
            default => ucfirst($status),
        };

        $log->update([
            'provider_response' => $providerResponse,
            'message_status' => $mappedStatus,
            'meta_message_id' => $metaMessageId,
            'sent_at' => $log->sent_at ?? now(),
            'delivered_at' => $status === 'delivered' ? ($log->delivered_at ?? now()) : $log->delivered_at,
            'read_at' => $status === 'read' ? ($log->read_at ?? now()) : $log->read_at,
            'error_message' => $providerResponse['error']['message'] ?? null,
            'failed_reason' => isset($providerResponse['error']) ? ($providerResponse['error']['message'] ?? 'Delivery failed') : null,
        ]);

        return $log->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function toMappedArray(WaMessageLog $log): array
    {
        return [
            'campaign_id' => $log->campaign_id,
            'lead_id' => $log->ca_id,
            'employee_id' => $log->employee_id,
            'mobile_no' => $log->mobile_no,
            'template_name' => $log->template_name,
            'meta_message_id' => $log->meta_message_id,
            'message' => $log->message,
            'status' => $log->message_status,
            'provider_response' => $log->provider_response,
            'error_message' => $log->error_message ?? $log->failed_reason,
            'sent_at' => $log->sent_at,
            'delivered_at' => $log->delivered_at,
            'read_at' => $log->read_at,
            'api_payload' => $log->api_payload,
        ];
    }

    public function logPayloadGenerated(WhatsAppCampaign $campaign, int $count): void
    {
        $this->activityLogService->log(
            'WHATSAPP_CAMPAIGN',
            'Payload Generated',
            (string) $campaign->id,
            $campaign->campaign_name.' · '.$count.' payloads mapped',
        );
    }

    /**
     * Persist a settings test-template send with full request and provider response.
     *
     * @param  array<string, mixed>  $apiPayload
     * @param  array{success: bool, status: string, meta_message_id: ?string, provider_response: array<string, mixed>, error_message: ?string}  $dispatchResult
     */
    public function storeTestTemplateSend(
        MessageTemplate $template,
        string $mobileNo,
        array $apiPayload,
        array $dispatchResult,
    ): WaMessageLog {
        $requestBody = $apiPayload['request_body'] ?? [];
        $providerResponse = array_merge(
            $dispatchResult['provider_response'] ?? [],
            ['request' => $requestBody],
        );

        return WaMessageLog::query()->create([
            'campaign_id' => null,
            'ca_id' => null,
            'mobile_no' => $mobileNo,
            'template_name' => $template->template_name,
            'language_code' => $template->language_code,
            'message' => $apiPayload['rendered_message'] ?? '',
            'api_payload' => array_merge($apiPayload, [
                'test_send' => true,
                'dispatch_request' => $requestBody,
            ]),
            'provider_response' => $providerResponse,
            'message_status' => $dispatchResult['status'],
            'meta_message_id' => $dispatchResult['meta_message_id'],
            'error_message' => $dispatchResult['error_message'],
            'failed_reason' => $dispatchResult['success'] ? null : $dispatchResult['error_message'],
            'queued_at' => now(),
            'sent_at' => $dispatchResult['success'] ? now() : null,
        ]);
    }

    public function logCampaignProcessed(WhatsAppCampaign $campaign): void
    {
        $this->activityLogService->log(
            'WHATSAPP_CAMPAIGN',
            'Campaign Processed',
            (string) $campaign->id,
            $campaign->campaign_name.' · mapping complete',
        );
    }

    public function assessEligibility(CaMaster $lead): array
    {
        return $this->eligibilityService->assess($lead, CommunicationEligibilityService::CHANNEL_WHATSAPP);
    }

    public function assessEligibilityForCampaign(CaMaster $lead): array
    {
        return $this->eligibilityService->assessForCampaign(
            $lead,
            CommunicationEligibilityService::CHANNEL_WHATSAPP,
        );
    }
}
