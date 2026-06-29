<?php

namespace App\Services\Sms;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\SmsCampaign;
use App\Models\SmsLog;
use App\Models\SourceLead;
use App\Models\State;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Concerns\SearchesListings;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SmsCampaignService
{
    use SearchesListings;

    public const STATUS_MAPPED = 'Mapped';

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
        private readonly SmsAlertMappingService $smsAlertMappingService,
        private readonly SmsSettingsService $smsSettingsService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly RbacService $rbacService,
    ) {}

    public function ensureCanManageCampaigns(?User $user): void
    {
        if (! in_array($this->rbacService->roleKey($user), ['admin', 'super_admin'], true)) {
            throw new AuthorizationException('Only Admin and Super Admin can create or modify SMS campaigns.');
        }
    }

    public function canManageCampaigns(?User $user): bool
    {
        return in_array($this->rbacService->roleKey($user), ['admin', 'super_admin'], true);
    }

    public function search(array $params = []): array
    {
        return $this->searchListing(SmsCampaign::query(), $params, 'sms_campaigns');
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(SmsCampaign::query(), [], 'sms_campaigns');
    }

    public function searchSmsLogs(array $params = []): array
    {
        return $this->searchListing(
            SmsLog::query()->with(['caMaster:ca_id,firm_name', 'campaign:id,campaign_name']),
            $params,
            'sms_logs',
        );
    }

    public function find(int|string $id): SmsCampaign
    {
        return SmsCampaign::query()
            ->withCount([
                'smsLogs as delivered_sms_count' => fn ($query) => $query->where('sms_status', 'Delivered'),
                'smsLogs as failed_sms_count' => fn ($query) => $query->where('sms_status', 'Failed'),
                'smsLogs as queued_sms_count' => fn ($query) => $query->where('sms_status', 'Queued'),
            ])
            ->findOrFail($id);
    }

    public function smsLogs(?int $campaignId = null, int $limit = 500): Collection
    {
        $params = $campaignId ? ['campaign_id' => $campaignId] : [];
        if ($limit && empty($params['page'])) {
            $params['per_page'] = min($limit, 500);
        }

        return collect($this->searchSmsLogs($params)['items']);
    }

    public function payloadPreview(int|string $id): array
    {
        $campaign = $this->find($id);
        $leads = $this->resolveCampaignAudience($campaign);
        $settings = $this->smsSettingsService->current();

        return $this->buildPreviewResponse($campaign, $leads, $settings, false);
    }

    public function generateMappedPayloadPreview(int|string $id, ?User $user = null): array
    {
        $this->ensureCanManageCampaigns($user ?? auth()->user());

        return DB::transaction(function () use ($id) {
            $campaign = $this->find($id);
            $leads = $this->resolveCampaignAudience($campaign);
            $settings = $this->smsSettingsService->current();
            $validation = $this->smsAlertMappingService->validateCampaignPreparation(
                $settings,
                $leads,
                (string) $campaign->message_template,
            );

            if (! $validation['valid']) {
                throw new InvalidArgumentException(implode(' ', $validation['errors']));
            }

            $deduped = $this->smsAlertMappingService->deduplicateLeadsByMobile($leads);
            $employeeId = $this->employeeDataScope->resolveEmployeeId(auth()->user());
            $createdLogs = [];

            SmsLog::query()->where('campaign_id', $campaign->id)->where('sms_status', self::STATUS_MAPPED)->delete();

            foreach ($deduped as $lead) {
                $message = $this->smsAlertMappingService->renderMessage($campaign->message_template, $lead);
                $prepared = $this->smsAlertMappingService->prepareForLead($lead, $message, $settings);

                if (! $prepared['valid']) {
                    continue;
                }

                $log = SmsLog::create([
                    'campaign_id' => $campaign->id,
                    'ca_id' => $lead->ca_id,
                    'employee_id' => $employeeId,
                    'mobile_no' => $prepared['payload']['mobileno'],
                    'sender_id' => $settings->sender_id,
                    'message' => $message,
                    'sms_status' => self::STATUS_MAPPED,
                    'provider_response' => $prepared['provider_response'],
                ]);
                $createdLogs[] = $log;
            }

            $campaign->update([
                'status' => 'Draft',
                'total_sms' => count($createdLogs),
                'queued_count' => count($createdLogs),
            ]);

            $this->activityLogService->log(
                'SMS_CAMPAIGN',
                'SMS Payload Mapped',
                (string) $campaign->id,
                $campaign->campaign_name.' · '.count($createdLogs).' mapped payload(s)',
            );

            $preview = $this->buildPreviewResponse($campaign->fresh(), $deduped, $settings, true);
            $preview['logs_created'] = count($createdLogs);

            return $preview;
        });
    }

    /**
     * @return array{valid: bool, errors: array<int, string>, warnings: array<int, string>}
     */
    public function validatePreparation(array $data): array
    {
        $settings = $this->smsSettingsService->current();
        $leads = $this->resolveAudience($data);

        return $this->smsAlertMappingService->validateCampaignPreparation(
            $settings,
            $leads,
            (string) ($data['message_template'] ?? ''),
        );
    }

    public function previewMessage(string $template, int $leadId): array
    {
        $lead = CaMaster::query()->with(['city', 'state'])->findOrFail($leadId);
        $rendered = $this->smsAlertMappingService->renderMessage($template, $lead);

        return [
            'lead_id' => $lead->ca_id,
            'firm_name' => $lead->firm_name,
            'mobile_no' => $lead->mobile_no,
            'preview' => $rendered,
            'character_count' => $this->smsAlertMappingService->calculateCharacterCount($rendered),
            'sms_count' => $this->smsAlertMappingService->calculateSmsCount($rendered),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPreviewResponse(
        SmsCampaign $campaign,
        Collection $leads,
        $settings,
        bool $persisted,
    ): array {
        $deduped = $this->smsAlertMappingService->deduplicateLeadsByMobile($leads);
        $validation = $this->smsAlertMappingService->validateCampaignPreparation(
            $settings,
            $leads,
            (string) $campaign->message_template,
        );
        $payloads = $this->smsAlertMappingService->buildCampaignPayloads($campaign, $deduped);
        $sample = collect($payloads)->firstWhere('valid', true);
        $sampleMessage = $sample['message'] ?? '';

        return [
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->campaign_name,
            'settings' => $this->smsSettingsService->toPublicArray($settings),
            'validation' => $validation,
            'estimated_recipients' => $deduped->count(),
            'duplicate_removed' => max(0, $leads->count() - $deduped->count()),
            'character_count' => $this->smsAlertMappingService->calculateCharacterCount($sampleMessage),
            'sms_count' => $this->smsAlertMappingService->calculateSmsCount($sampleMessage),
            'payloads' => collect($payloads)->map(function (array $row) {
                if (! $row['valid'] || ! is_array($row['api_payload'])) {
                    return $row;
                }
                $row['display_payload'] = $this->smsAlertMappingService->maskPayloadForDisplay($row['api_payload']);

                return $row;
            })->values()->all(),
            'sample_payload' => $sample && is_array($sample['api_payload'] ?? null)
                ? $this->smsAlertMappingService->maskPayloadForDisplay($sample['api_payload'])
                : null,
            'valid_count' => collect($payloads)->where('valid', true)->count(),
            'invalid_count' => collect($payloads)->where('valid', false)->count(),
            'persisted' => $persisted,
            'dispatch' => 'mapped_not_sent',
        ];
    }

    private function resolveCampaignAudience(SmsCampaign $campaign): Collection
    {
        return $this->resolveAudience(array_merge([
            'audience_mode' => $campaign->audience_mode,
            'ca_ids' => $campaign->selected_ca_ids ?? [],
        ], $campaign->audience_filters ?? []));
    }

    public function create(array $data): SmsCampaign
    {
        $this->ensureCanManageCampaigns(auth()->user());

        return DB::transaction(function () use ($data) {
            $recipients = $this->resolveAudience($data);

            if ($recipients->isEmpty()) {
                throw new InvalidArgumentException('No leads matched the selected audience.');
            }

            $scheduledAt = isset($data['scheduled_at']) && $data['scheduled_at']
                ? Carbon::parse($data['scheduled_at'])
                : null;
            $senderId = $data['sender_id'] ?? $this->smsSettingsService->current()->sender_id ?? 'CACLDK';
            $saveAsDraft = (bool) ($data['save_as_draft'] ?? true);

            $campaign = SmsCampaign::create([
                'campaign_name' => $data['campaign_name'],
                'campaign_type' => $data['campaign_type'],
                'audience_mode' => $data['audience_mode'],
                'audience_label' => $this->buildAudienceLabel($data),
                'audience_filters' => $this->extractAudienceFilters($data),
                'selected_ca_ids' => $data['audience_mode'] === 'selected_leads'
                    ? array_values(array_map('intval', $data['ca_ids'] ?? []))
                    : null,
                'sender_id' => $senderId,
                'message_template' => $data['message_template'],
                'scheduled_at' => $scheduledAt,
                'status' => $saveAsDraft ? 'Draft' : 'Draft',
                'performed_by' => $data['performed_by'] ?? auth()->user()?->name ?? 'System',
                'total_sms' => $this->smsAlertMappingService->deduplicateLeadsByMobile($recipients)->count(),
                'queued_count' => 0,
                'skipped_count' => 0,
            ]);

            $this->activityLogService->log(
                'SMS_CAMPAIGN',
                'SMS Campaign Created',
                (string) $campaign->id,
                $campaign->campaign_name.' · '.$campaign->total_sms.' recipients · '.$campaign->audience_label,
            );

            return $campaign->fresh();
        });
    }

    public function generateMessageLogs(int $campaignId): void
    {
        // Mapping phase: log generation happens via generateMappedPayloadPreview only.
    }

    public function markLogGenerationFailed(int $campaignId): void
    {
        SmsCampaign::query()->where('id', $campaignId)->update(['status' => 'Failed']);
    }

    public function process(int|string $id): SmsCampaign
    {
        $this->ensureCanManageCampaigns(auth()->user());

        throw new InvalidArgumentException(
            'SMS live dispatch is not enabled. Complete mapping preview first; sending will be available after API credentials are configured.',
        );
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

    public function update(SmsCampaign $campaign, array $data): SmsCampaign
    {
        $this->ensureCanManageCampaigns(auth()->user());

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
            'SMS_CAMPAIGN',
            'Campaign Update',
            (string) $campaign->id,
            $campaign->campaign_name,
        );

        return $campaign->fresh();
    }

    public function delete(SmsCampaign $campaign): void
    {
        $this->ensureCanManageCampaigns(auth()->user());

        if ($campaign->status === 'Processing') {
            throw new InvalidArgumentException('Cannot delete a campaign while it is processing.');
        }

        $name = $campaign->campaign_name;
        $id = (string) $campaign->id;

        DB::transaction(function () use ($campaign) {
            SmsLog::query()->where('campaign_id', $campaign->id)->delete();
            $campaign->delete();
        });

        $this->activityLogService->log('SMS_CAMPAIGN', 'Campaign Delete', $id, $name);
    }
}
