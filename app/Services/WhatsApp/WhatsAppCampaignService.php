<?php

namespace App\Services\WhatsApp;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\SourceLead;
use App\Models\State;
use App\Models\WaMessageLog;
use App\Models\WhatsAppCampaign;
use App\Services\Activity\ActivityLogService;
use App\Services\Campaign\CampaignMessageLogProcessor;
use App\Services\Communication\CommunicationEligibilityService;
use App\Services\Concerns\SearchesListings;
use App\Services\Notifications\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WhatsAppCampaignService
{
    use SearchesListings;

    private const AUDIENCE_LABELS = [
        'selected_leads' => 'Selected Leads',
        'all_leads' => 'All Leads',
        'city' => 'City',
        'state' => 'State',
        'source' => 'Source',
        'rating' => 'Rating',
        'team_size' => 'Team Size',
        'existing_software' => 'Existing Software',
    ];

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly CommunicationEligibilityService $eligibilityService,
        private readonly NotificationService $notificationService,
        private readonly CampaignMessageLogProcessor $campaignLogProcessor,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(WhatsAppCampaign::query(), $params, 'whatsapp_campaigns');
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(WhatsAppCampaign::query(), [], 'whatsapp_campaigns');
    }

    public function searchMessageLogs(array $params = []): array
    {
        return $this->searchListing(
            WaMessageLog::query()->with(['caMaster:ca_id,firm_name', 'campaign:id,campaign_name']),
            $params,
            'wa_message_logs',
        );
    }

    public function find(int|string $id): WhatsAppCampaign
    {
        return WhatsAppCampaign::query()
            ->withCount([
                'messageLogs as delivered_messages_count' => fn ($query) => $query->where('message_status', 'Delivered'),
                'messageLogs as failed_messages_count' => fn ($query) => $query->where('message_status', 'Failed'),
                'messageLogs as queued_messages_count' => fn ($query) => $query->where('message_status', 'Queued'),
            ])
            ->findOrFail($id);
    }

    public function messageLogs(?int $campaignId = null, int $limit = 500): Collection
    {
        $params = $campaignId ? ['campaign_id' => $campaignId] : [];
        if ($limit && empty($params['page'])) {
            $params['per_page'] = min($limit, 500);
        }

        return collect($this->searchMessageLogs($params)['items']);
    }

    public function create(array $data): WhatsAppCampaign
    {
        return DB::transaction(function () use ($data) {
            $recipients = $this->resolveAudience($data);

            if ($recipients->isEmpty()) {
                throw new InvalidArgumentException('No leads matched the selected audience.');
            }

            $scheduledAt = isset($data['scheduled_at']) && $data['scheduled_at']
                ? Carbon::parse($data['scheduled_at'])
                : null;
            $processNow = ! $scheduledAt || $scheduledAt->lte(now());

            $campaign = WhatsAppCampaign::create([
                'campaign_name' => $data['campaign_name'],
                'campaign_type' => $data['campaign_type'],
                'audience_mode' => $data['audience_mode'],
                'audience_label' => $this->buildAudienceLabel($data),
                'audience_filters' => $this->extractAudienceFilters($data),
                'selected_ca_ids' => $data['audience_mode'] === 'selected_leads'
                    ? array_values(array_map('intval', $data['ca_ids'] ?? []))
                    : null,
                'message_template' => $data['message_template'],
                'scheduled_at' => $scheduledAt,
                'status' => $processNow ? 'Processing' : 'Scheduled',
                'performed_by' => $data['performed_by'] ?? 'System',
                'total_messages' => $recipients->count(),
                'queued_count' => 0,
                'skipped_count' => 0,
            ]);

            if ($this->campaignLogProcessor->shouldQueue($recipients->count())) {
                $this->campaignLogProcessor->dispatch('whatsapp', $campaign->id);

                return $campaign->fresh();
            }

            $this->populateMessageLogs($campaign, $recipients, $data, $processNow);

            return $campaign->fresh();
        });
    }

    public function generateMessageLogs(int $campaignId): void
    {
        DB::transaction(function () use ($campaignId) {
            $campaign = WhatsAppCampaign::query()->findOrFail($campaignId);

            if (WaMessageLog::query()->where('campaign_id', $campaignId)->exists()) {
                if ($campaign->status === 'Processing') {
                    $this->simulateDelivery($campaign);
                }

                return;
            }

            $data = $this->campaignDataFromModel($campaign);
            $recipients = $this->resolveAudience($data);
            $processNow = ! $campaign->scheduled_at || $campaign->scheduled_at->lte(now());

            $this->populateMessageLogs($campaign, $recipients, $data, $processNow);
        });
    }

    public function markLogGenerationFailed(int $campaignId): void
    {
        WhatsAppCampaign::query()
            ->where('id', $campaignId)
            ->update(['status' => 'Failed']);
    }

    private function populateMessageLogs(
        WhatsAppCampaign $campaign,
        Collection $recipients,
        array $data,
        bool $processNow,
    ): WhatsAppCampaign {
        $now = now();
        $queuedCount = 0;
        $skippedCount = 0;
        $skipSummary = [];

        foreach ($recipients as $lead) {
            $eligibility = $this->eligibilityService->assess($lead, CommunicationEligibilityService::CHANNEL_WHATSAPP);

            if ($eligibility['eligible']) {
                WaMessageLog::create([
                    'campaign_id' => $campaign->id,
                    'ca_id' => $lead->ca_id,
                    'mobile_no' => $lead->mobile_no,
                    'message' => $this->renderMessage($data['message_template'], $lead),
                    'message_status' => 'Queued',
                    'queued_at' => $now,
                ]);
                $queuedCount++;

                continue;
            }

            WaMessageLog::create([
                'campaign_id' => $campaign->id,
                'ca_id' => $lead->ca_id,
                'mobile_no' => $lead->mobile_no,
                'message' => $this->renderMessage($data['message_template'], $lead),
                'message_status' => 'Skipped',
                'failed_reason' => $eligibility['skip_reason'],
            ]);
            $skippedCount++;
            $skipSummary[$eligibility['skip_reason']] = ($skipSummary[$eligibility['skip_reason']] ?? 0) + 1;
        }

        $campaign->update([
            'queued_count' => $queuedCount,
            'skipped_count' => $skippedCount,
        ]);

        if ($processNow) {
            $this->simulateDelivery($campaign);
        }

        $campaign = $campaign->fresh();

        $this->activityLogService->log(
            'WHATSAPP_CAMPAIGN',
            'WhatsApp Campaign Create',
            (string) $campaign->id,
            $campaign->campaign_name.' · '.$campaign->total_messages.' recipients · '.$campaign->audience_label,
        );

        $this->logComplianceSkips($campaign, $skipSummary);

        return $campaign;
    }

    private function campaignDataFromModel(WhatsAppCampaign $campaign): array
    {
        return array_merge([
            'audience_mode' => $campaign->audience_mode,
            'ca_ids' => $campaign->selected_ca_ids ?? [],
            'message_template' => $campaign->message_template,
        ], $campaign->audience_filters ?? []);
    }

    public function process(int|string $id): WhatsAppCampaign
    {
        return DB::transaction(function () use ($id) {
            $campaign = WhatsAppCampaign::query()->findOrFail($id);

            if (! in_array($campaign->status, ['Scheduled', 'Draft'], true)) {
                throw new InvalidArgumentException('Campaign has already been processed.');
            }

            $campaign->update(['status' => 'Processing']);
            $this->simulateDelivery($campaign);
            $campaign = $campaign->fresh();

            $this->notificationService->campaignCompleted(
                'whatsapp',
                $campaign->campaign_name,
                (int) ($campaign->total_messages ?? 0),
                (int) ($campaign->delivered_count ?? 0),
                $campaign->id,
            );

            return $campaign;
        });
    }

    public function dashboardMetrics(): array
    {
        $statusCounts = WaMessageLog::query()
            ->selectRaw("COUNT(*) FILTER (WHERE message_status = 'Delivered') as delivered")
            ->selectRaw("COUNT(*) FILTER (WHERE message_status = 'Failed') as failed")
            ->selectRaw("COUNT(*) FILTER (WHERE message_status = 'Queued') as queued")
            ->selectRaw('COUNT(*) as total')
            ->first();

        return [
            'whatsapp_campaigns_total' => WhatsAppCampaign::query()->count(),
            'whatsapp_messages_total' => (int) ($statusCounts->total ?? 0),
            'whatsapp_delivered' => (int) ($statusCounts->delivered ?? 0),
            'whatsapp_failed' => (int) ($statusCounts->failed ?? 0),
            'whatsapp_queued' => (int) ($statusCounts->queued ?? 0),
        ];
    }

    private function resolveAudience(array $data): Collection
    {
        $query = CaMaster::query();

        return match ($data['audience_mode']) {
            'selected_leads' => $query->whereIn('ca_id', $data['ca_ids'] ?? [])->get(),
            'all_leads' => $query->get(),
            'city' => $query->where('city_id', (int) $data['city_id'])->get(),
            'state' => $query->where('state_id', (int) $data['state_id'])->get(),
            'source' => $query->where('source_id', (int) $data['source_id'])->get(),
            'rating' => $query->where('rating', (int) $data['rating'])->get(),
            'team_size' => $query->where('team_size', (int) $data['team_size'])->get(),
            'existing_software' => $query->where('existing_software', $data['existing_software'])->get(),
            default => collect(),
        };
    }

    private function extractAudienceFilters(array $data): ?array
    {
        return match ($data['audience_mode']) {
            'city' => ['city_id' => (int) $data['city_id']],
            'state' => ['state_id' => (int) $data['state_id']],
            'source' => ['source_id' => (int) $data['source_id']],
            'rating' => ['rating' => (int) $data['rating']],
            'team_size' => ['team_size' => (int) $data['team_size']],
            'existing_software' => ['existing_software' => $data['existing_software']],
            default => null,
        };
    }

    private function buildAudienceLabel(array $data): string
    {
        $base = self::AUDIENCE_LABELS[$data['audience_mode']] ?? $data['audience_mode'];

        return match ($data['audience_mode']) {
            'selected_leads' => $base.' ('.count($data['ca_ids'] ?? []).' leads)',
            'city' => $base.': '.(City::query()->where('city_id', $data['city_id'])->value('city_name') ?? '—'),
            'state' => $base.': '.(State::query()->where('state_id', $data['state_id'])->value('state_name') ?? '—'),
            'source' => $base.': '.(SourceLead::query()->where('source_id', $data['source_id'])->value('source_name') ?? '—'),
            'rating' => $base.': '.$data['rating'].' ★',
            'team_size' => $base.': '.$data['team_size'],
            'existing_software' => $base.': '.($data['existing_software'] ?? '—'),
            default => $base,
        };
    }

    private function renderMessage(string $template, CaMaster $lead): string
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
        ];

        return strtr($template, $replacements);
    }

    private function simulateDelivery(WhatsAppCampaign $campaign): void
    {
        $logs = WaMessageLog::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->get();

        $delivered = 0;
        $failed = 0;
        $queued = 0;
        $skipped = 0;
        $now = now();

        foreach ($logs as $index => $log) {
            if ($log->message_status === 'Skipped') {
                $skipped++;

                continue;
            }

            $log->update([
                'message_status' => 'Processing',
                'sent_at' => $now->copy()->addMilliseconds($index * 5),
            ]);

            $shouldFail = ! $log->mobile_no || strlen(preg_replace('/\D/', '', $log->mobile_no)) < 10;

            if (! $shouldFail && random_int(1, 100) > 96) {
                $shouldFail = true;
            }

            if ($shouldFail) {
                $log->update([
                    'message_status' => 'Failed',
                    'failed_reason' => ! $log->mobile_no ? 'Missing mobile number' : 'Simulation: delivery failed',
                ]);
                $failed++;

                continue;
            }

            $log->update([
                'message_status' => 'Delivered',
                'delivered_at' => $now->copy()->addMilliseconds(($index * 5) + 10),
            ]);
            $delivered++;
        }

        $campaign->update([
            'status' => 'Completed',
            'delivered_count' => $delivered,
            'failed_count' => $failed,
            'queued_count' => $queued,
            'skipped_count' => $skipped,
        ]);
    }

    public function update(WhatsAppCampaign $campaign, array $data): WhatsAppCampaign
    {
        if ($campaign->status === 'Processing') {
            throw new InvalidArgumentException('Cannot edit a campaign while it is processing.');
        }

        $campaign->update([
            'campaign_name' => $data['campaign_name'] ?? $campaign->campaign_name,
            'message_template' => $data['message_template'] ?? $campaign->message_template,
            'scheduled_at' => array_key_exists('scheduled_at', $data)
                ? ($data['scheduled_at'] ? Carbon::parse($data['scheduled_at']) : null)
                : $campaign->scheduled_at,
        ]);

        $this->activityLogService->log(
            'WHATSAPP_CAMPAIGN',
            'Campaign Update',
            (string) $campaign->id,
            $campaign->campaign_name,
        );

        return $campaign->fresh();
    }

    public function delete(WhatsAppCampaign $campaign): void
    {
        if ($campaign->status === 'Processing') {
            throw new InvalidArgumentException('Cannot delete a campaign while it is processing.');
        }

        $name = $campaign->campaign_name;
        $id = (string) $campaign->id;

        DB::transaction(function () use ($campaign) {
            WaMessageLog::query()->where('campaign_id', $campaign->id)->delete();
            $campaign->delete();
        });

        $this->activityLogService->log('WHATSAPP_CAMPAIGN', 'Campaign Delete', $id, $name);
    }

    private function logComplianceSkips(WhatsAppCampaign $campaign, array $skipSummary): void
    {
        $dnd = $skipSummary[CommunicationEligibilityService::SKIP_DND] ?? 0;
        $noConsent = $skipSummary[CommunicationEligibilityService::SKIP_NO_CONSENT] ?? 0;

        if ($dnd === 0 && $noConsent === 0) {
            return;
        }

        $parts = [];
        if ($dnd > 0) {
            $parts[] = $dnd.' DND/opt-out';
        }
        if ($noConsent > 0) {
            $parts[] = $noConsent.' no consent';
        }

        $this->activityLogService->log(
            'WHATSAPP_CAMPAIGN',
            'Campaign Skip',
            (string) $campaign->id,
            $campaign->campaign_name.' · skipped '.implode(', ', $parts),
        );
    }
}
