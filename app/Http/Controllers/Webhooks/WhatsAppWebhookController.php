<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WaMessageLog;
use App\Models\WaMessageLogStatus;
use App\Services\WhatsApp\WhatsAppCampaignService;
use App\Services\WhatsApp\WhatsAppMetaTemplateService;
use App\Services\WhatsApp\WhatsAppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppSettingsService $settingsService,
        private readonly WhatsAppMetaTemplateService $metaTemplateService,
    ) {}

    public function verify(Request $request): Response|JsonResponse
    {
        $settings = $this->settingsService->current();

        if (! $settings->hasWebhookVerifyToken()) {
            return response()->json(['success' => false, 'message' => 'Webhook verify token is not configured.'], 403);
        }

        $mode = (string) $request->query('hub_mode');
        $token = (string) $request->query('hub_verify_token');
        $challenge = (string) $request->query('hub_challenge');

        if ($mode === 'subscribe' && hash_equals((string) $settings->webhook_verify_token, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::info('whatsapp.webhook.received', ['payload' => $payload]);

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $field = (string) ($change['field'] ?? '');
                $value = $change['value'] ?? [];

                if ($field === 'messages') {
                    foreach ($value['statuses'] ?? [] as $statusUpdate) {
                        $this->applyMessageStatusUpdate($statusUpdate);
                    }

                    continue;
                }

                if ($field === 'message_template_status_update') {
                    $this->metaTemplateService->applyTemplateStatusWebhook(is_array($value) ? $value : []);

                    continue;
                }

                // Legacy payloads without field — still try message statuses.
                if ($field === '' && isset($value['statuses'])) {
                    foreach ($value['statuses'] as $statusUpdate) {
                        $this->applyMessageStatusUpdate($statusUpdate);
                    }
                }
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * @param  array<string, mixed>  $statusUpdate
     */
    private function applyMessageStatusUpdate(array $statusUpdate): void
    {
        $messageId = (string) ($statusUpdate['id'] ?? '');
        if ($messageId === '') {
            return;
        }

        $log = WaMessageLog::query()->where('meta_message_id', $messageId)->first();
        if (! $log) {
            return;
        }

        $status = strtolower((string) ($statusUpdate['status'] ?? ''));
        $providerResponse = array_merge($log->provider_response ?? [], [
            'webhook_status' => $statusUpdate,
        ]);

        $updates = [
            'provider_response' => $providerResponse,
        ];

        if ($status === 'sent') {
            $updates['message_status'] = WaMessageLogStatus::SENT;
            $updates['sent_at'] = $log->sent_at ?? now();
        } elseif ($status === 'delivered') {
            $updates['message_status'] = WaMessageLogStatus::DELIVERED;
            $updates['delivered_at'] = $log->delivered_at ?? now();
        } elseif ($status === 'read') {
            $updates['message_status'] = WaMessageLogStatus::READ;
            $updates['read_at'] = $log->read_at ?? now();
        } elseif ($status === 'failed') {
            $updates['message_status'] = WaMessageLogStatus::FAILED;
            $updates['error_message'] = $statusUpdate['errors'][0]['title'] ?? 'Delivery failed';
            $updates['failed_reason'] = $updates['error_message'];
        }

        $log->update($updates);

        if ($log->campaign_id) {
            app(WhatsAppCampaignService::class)->syncCampaignStats((int) $log->campaign_id);
        }
    }
}
