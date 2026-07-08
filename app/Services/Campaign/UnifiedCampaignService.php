<?php

namespace App\Services\Campaign;

use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\SmsCampaign;
use App\Models\SmsLog;
use App\Models\WaMessageLog;
use App\Models\WhatsAppCampaign;
use App\Services\Activity\ActivityLogService;
use App\Services\Email\EmailCampaignService;
use App\Services\Sms\SmsCampaignService;
use App\Services\WhatsApp\WhatsAppCampaignService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UnifiedCampaignService
{
    public function __construct(
        private readonly CampaignScopeService $scopeService,
        private readonly EmailCampaignService $emailCampaignService,
        private readonly SmsCampaignService $smsCampaignService,
        private readonly WhatsAppCampaignService $whatsappCampaignService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function search(array $params = []): array
    {
        $channels = $this->resolveChannels($params['channel'] ?? null);
        $items = collect();

        foreach ($channels as $channel) {
            $items = $items->merge(
                $this->channelRows($channel, $params)->map(fn ($row) => $this->normalizeRow($channel, $row)),
            );
        }

        if (! empty($params['q'])) {
            $q = strtolower((string) $params['q']);
            $items = $items->filter(function ($row) use ($q) {
                return str_contains(strtolower((string) $row['campaign_name']), $q)
                    || str_contains(strtolower((string) ($row['template_name'] ?? '')), $q);
            });
        }

        if (! empty($params['created_by'])) {
            $creator = strtolower((string) $params['created_by']);
            $items = $items->filter(fn ($row) => str_contains(strtolower((string) ($row['created_by'] ?? '')), $creator));
        }

        if (! empty($params['date_from'])) {
            $from = Carbon::parse($params['date_from'])->startOfDay();
            $items = $items->filter(fn ($row) => Carbon::parse($row['created_at'])->gte($from));
        }

        if (! empty($params['date_to'])) {
            $to = Carbon::parse($params['date_to'])->endOfDay();
            $items = $items->filter(fn ($row) => Carbon::parse($row['created_at'])->lte($to));
        }

        $sort = $params['sort'] ?? 'created_at';
        $dir = strtolower($params['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $items = $items->sortBy($sort, SORT_REGULAR, $dir === 'desc')->values();

        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($params['per_page'] ?? 20)));
        $total = $items->count();
        $paged = $items->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return [
            'items' => $paged,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(string $channel, int $id): array
    {
        $campaign = $this->scopeService->ensureCanAccessCampaign($channel, $id);
        $normalized = $this->normalizeRow($channel, $campaign);
        $normalized['recipients'] = $this->recipientRows($channel, $id);
        $normalized['activity_timeline'] = $this->activityTimeline($channel, $id);
        $normalized['template_preview'] = $campaign->template_snapshot ?? [];
        $normalized['sender_details'] = $campaign->sender_snapshot ?? [];
        $normalized['status_history'] = $campaign->status_history ?? [];
        $normalized['available_actions'] = $this->availableActions($channel, $campaign);

        return $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recipientRows(string $channel, int $campaignId): array
    {
        return match (strtolower($channel)) {
            'email' => EmailLog::query()
                ->with('caMaster:ca_id,firm_name,ca_name')
                ->where('campaign_id', $campaignId)
                ->orderByDesc('id')
                ->limit(1000)
                ->get()
                ->map(fn (EmailLog $log) => [
                    'lead' => $log->caMaster?->ca_name ?? '—',
                    'firm' => $log->caMaster?->firm_name ?? '—',
                    'email' => $log->recipient_email,
                    'status' => $log->email_status,
                    'sent_at' => $log->sent_at,
                    'failed_reason' => $log->failed_reason ?? $log->error_message,
                    'bounce_reason' => $log->failed_reason,
                    'opened_at' => $log->opened_at,
                ])->all(),
            'sms' => SmsLog::query()
                ->with('caMaster:ca_id,firm_name,ca_name')
                ->where('campaign_id', $campaignId)
                ->orderByDesc('id')
                ->limit(1000)
                ->get()
                ->map(fn (SmsLog $log) => [
                    'lead' => $log->caMaster?->ca_name ?? '—',
                    'firm' => $log->caMaster?->firm_name ?? '—',
                    'mobile' => $log->mobile_no,
                    'sender_id' => $log->sender_id,
                    'dlt_template' => $log->dlt_template_id,
                    'status' => $log->sms_status,
                    'sent_at' => $log->sent_at,
                    'failed_reason' => $log->failed_reason ?? $log->error_message,
                    'provider_response' => is_array($log->provider_response)
                        ? json_encode($log->provider_response)
                        : $log->provider_response,
                ])->all(),
            'whatsapp' => WaMessageLog::query()
                ->with('caMaster:ca_id,firm_name,ca_name')
                ->where('campaign_id', $campaignId)
                ->orderByDesc('id')
                ->limit(1000)
                ->get()
                ->map(fn (WaMessageLog $log) => [
                    'lead' => $log->caMaster?->ca_name ?? '—',
                    'firm' => $log->caMaster?->firm_name ?? '—',
                    'mobile' => $log->mobile_no,
                    'whatsapp_number' => $log->sender_snapshot['display_phone_number'] ?? null,
                    'template_name' => $log->template_name,
                    'meta_message_id' => $log->meta_message_id,
                    'status' => $log->message_status,
                    'sent_at' => $log->sent_at,
                    'delivered_at' => $log->delivered_at,
                    'read_at' => $log->read_at,
                    'failed_reason' => $log->failed_reason ?? $log->error_message,
                ])->all(),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function resolveChannels(?string $channel): array
    {
        if ($channel && in_array(strtolower($channel), ['email', 'sms', 'whatsapp'], true)) {
            return [strtolower($channel)];
        }

        return ['email', 'sms', 'whatsapp'];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function channelRows(string $channel, array $params): Collection
    {
        $query = match ($channel) {
            'email' => EmailCampaign::query(),
            'sms' => SmsCampaign::query(),
            'whatsapp' => WhatsAppCampaign::query(),
            default => EmailCampaign::query()->whereRaw('1 = 0'),
        };

        $table = $query->getModel()->getTable();
        $this->scopeService->applyCreatorScope($query, auth()->user(), $table);

        if (! empty($params['status'])) {
            $query->where($table.'.status', $params['status']);
        }

        if (! empty($params['audience_mode'])) {
            $query->where($table.'.audience_mode', $params['audience_mode']);
        }

        return $query->orderByDesc($table.'.created_at')->limit(500)->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRow(string $channel, mixed $row): array
    {
        $total = (int) ($row->total_emails ?? $row->total_sms ?? $row->total_messages ?? 0);
        $sent = (int) ($row->sent_count ?? $row->delivered_count ?? 0);
        $delivered = (int) ($row->delivered_count ?? 0);
        $failed = (int) ($row->failed_count ?? 0);
        $pending = (int) ($row->pending_count ?? $row->queued_count ?? 0);
        $valid = (int) ($row->valid_emails_count ?? $total);
        $invalid = (int) ($row->invalid_count ?? $row->invalid_emails_count ?? 0);
        $duplicate = (int) ($row->duplicate_count ?? $row->duplicate_emails_count ?? 0);
        $skipped = (int) ($row->skipped_count ?? 0);
        $bounce = (int) ($row->bounce_count ?? 0);

        $templateSnapshot = is_array($row->template_snapshot) ? $row->template_snapshot : [];
        $senderSnapshot = is_array($row->sender_snapshot) ? $row->sender_snapshot : [];

        return [
            'id' => $row->id,
            'campaign_uuid' => $row->campaign_uuid,
            'channel' => ucfirst($channel),
            'channel_key' => $channel,
            'campaign_name' => $row->campaign_name,
            'campaign_type' => $row->campaign_type,
            'audience_mode' => $row->audience_mode,
            'audience_label' => $row->audience_label,
            'template_name' => $templateSnapshot['template_name'] ?? $row->template_name ?? $templateSnapshot['subject'] ?? '—',
            'sender_used' => $this->senderLabel($channel, $senderSnapshot, $row),
            'created_by' => $row->performed_by,
            'created_by_user_id' => $row->created_by_user_id,
            'created_at' => $row->created_at,
            'scheduled_at' => $row->scheduled_at,
            'completed_at' => $row->completed_at,
            'status' => $row->status,
            'stats' => [
                'total_recipients' => $total,
                'valid_recipients' => $valid,
                'sent' => $sent,
                'delivered' => $delivered,
                'failed' => $failed,
                'pending' => $pending,
                'invalid' => $invalid,
                'duplicate' => $duplicate,
                'skipped' => $skipped,
                'bounce' => $bounce,
            ],
            'progress' => [
                'sent_rate' => $total > 0 ? round(($sent / $total) * 100, 1) : 0,
                'delivery_rate' => $total > 0 ? round(($delivered / $total) * 100, 1) : 0,
                'failure_rate' => $total > 0 ? round(($failed / $total) * 100, 1) : 0,
            ],
            'retry_count' => (int) ($row->retry_count ?? 0),
            'available_actions' => $this->availableActions($channel, $row),
        ];
    }

    /**
     * @param  array<string, mixed>  $senderSnapshot
     */
    private function senderLabel(string $channel, array $senderSnapshot, mixed $row): string
    {
        return match ($channel) {
            'email' => (string) ($senderSnapshot['from_email'] ?? $senderSnapshot['account_name'] ?? 'Default Email'),
            'sms' => (string) ($senderSnapshot['sender_id'] ?? $row->sender_id ?? '—'),
            'whatsapp' => (string) ($senderSnapshot['display_phone_number'] ?? $senderSnapshot['phone_number_id'] ?? '—'),
            default => '—',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activityTimeline(string $channel, int $id): array
    {
        $module = match (strtolower($channel)) {
            'email' => 'EMAIL_CAMPAIGN',
            'sms' => 'SMS_CAMPAIGN',
            'whatsapp' => 'WHATSAPP_CAMPAIGN',
            default => 'CAMPAIGN',
        };

        return \App\Models\ActivityLog::query()
            ->where('module_name', $module)
            ->where('record_id', (string) $id)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->map(fn ($log) => [
                'action' => $log->action,
                'detail' => $log->description,
                'performed_by' => $log->performed_by,
                'created_at' => $log->created_at,
            ])->all();
    }

    /**
     * @return list<string>
     */
    private function availableActions(string $channel, mixed $campaign): array
    {
        $status = (string) $campaign->status;
        $actions = ['view', 'export'];

        if (in_array($status, ['Draft', 'Scheduled'], true)) {
            $actions[] = 'edit';
            $actions[] = 'duplicate';
            $actions[] = 'cancel';
        }

        if ($status === 'Paused') {
            $actions[] = 'resume';
            $actions[] = 'cancel';
        }

        if (in_array($status, ['Scheduled', 'Processing', 'Draft'], true)) {
            $actions[] = 'pause';
        }

        if (in_array($status, ['Completed', 'Partial', 'Failed'], true) && (int) $campaign->failed_count > 0) {
            $actions[] = 'retry_failed';
        }

        if (! in_array($status, ['Processing'], true)) {
            $actions[] = 'delete';
        }

        $actions[] = 'duplicate';

        return array_values(array_unique($actions));
    }
}
