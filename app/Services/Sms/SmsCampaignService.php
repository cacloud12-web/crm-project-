<?php

namespace App\Services\Sms;

use App\Jobs\Sms\ProcessSmsCampaignJob;
use App\Models\CaMaster;
use App\Models\City;
use App\Models\SmsCampaign;
use App\Support\Queue\QueueDispatcher;
use App\Models\SmsLog;
use App\Models\SmsLogStatus;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
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
        private readonly SmsDltTemplateService $smsDltTemplateService,
        private readonly SmsDispatchService $smsDispatchService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly RbacService $rbacService,
    ) {}

    public function ensureCanManageCampaigns(?User $user): void
    {
        if (! $this->canManageCampaigns($user)) {
            throw new AuthorizationException('Only Admin and Super Admin can create or modify SMS campaigns.');
        }
    }

    public function ensureCanSendSms(?User $user): void
    {
        if (! $this->canSendSms($user)) {
            throw new AuthorizationException('You do not have permission to send SMS campaigns.');
        }
    }

    public function canSendSms(?User $user): bool
    {
        return in_array($this->rbacService->roleKey($user), ['admin', 'super_admin', 'manager', 'employee'], true);
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
            $dltTemplate = $this->smsAlertMappingService->resolveCampaignDltTemplate($campaign);
            $dltTemplateId = $dltTemplate
                ? $this->smsAlertMappingService->resolveCampaignDltTemplateId($dltTemplate->dlt_template_id)
                : null;
            $validation = $this->smsAlertMappingService->validateCampaignPreparation(
                $settings,
                $leads,
                (string) $campaign->message_template,
                $dltTemplateId,
            );

            if (! $validation['valid']) {
                throw new InvalidArgumentException(implode(' ', $validation['errors']));
            }

            $deduped = $this->smsAlertMappingService->deduplicateLeadsByMobile($leads);
            $employeeId = $this->employeeDataScope->resolveEmployeeId(auth()->user());
            $createdLogs = [];

            SmsLog::query()->where('campaign_id', $campaign->id)->where('sms_status', self::STATUS_MAPPED)->delete();

            foreach ($deduped as $lead) {
                $message = $dltTemplate
                    ? $this->smsDltTemplateService->renderBody($dltTemplate, $lead)
                    : $this->smsAlertMappingService->renderMessage((string) $campaign->message_template, $lead);
                $prepared = $this->smsAlertMappingService->prepareForLead($lead, $message, $settings, $dltTemplateId);

                if (! $prepared['valid']) {
                    continue;
                }

                $log = SmsLog::create([
                    'campaign_id' => $campaign->id,
                    'sms_template_id' => $dltTemplate?->id,
                    'template_name' => $dltTemplate?->template_name,
                    'dlt_template_id' => $dltTemplateId,
                    'ca_id' => $lead->ca_id,
                    'employee_id' => $employeeId,
                    'mobile_no' => $prepared['payload']['mobileno'],
                    'sender_id' => $dltTemplate?->sender_id ?? $settings->sender_id,
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
        $template = $this->smsDltTemplateService->findApproved((int) $data['sms_template_id']);
        $messageTemplate = $template->body_template;

        return $this->smsAlertMappingService->validateCampaignPreparation(
            $settings,
            $leads,
            (string) $messageTemplate,
            $this->smsAlertMappingService->resolveCampaignDltTemplateId($template->dlt_template_id),
        );
    }

    public function previewMessage(string $template, int $leadId, ?int $smsTemplateId = null): array
    {
        if ($smsTemplateId) {
            $dltTemplate = $this->smsDltTemplateService->findApproved($smsTemplateId);

            return $this->smsDltTemplateService->preview($dltTemplate, $leadId);
        }

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
        $dltTemplate = $this->smsAlertMappingService->resolveCampaignDltTemplate($campaign);
        $dltTemplateId = $dltTemplate
            ? $this->smsAlertMappingService->resolveCampaignDltTemplateId($dltTemplate->dlt_template_id)
            : null;
        $validation = $this->smsAlertMappingService->validateCampaignPreparation(
            $settings,
            $leads,
            (string) $campaign->message_template,
            $dltTemplateId,
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
        $this->ensureCanSendSms(auth()->user());

        return DB::transaction(function () use ($data) {
            $recipients = $this->resolveAudience($data);

            if ($recipients->isEmpty()) {
                throw new InvalidArgumentException('No leads matched the selected audience.');
            }

            $template = $this->resolveCampaignTemplate($data);
            $scheduledAt = isset($data['scheduled_at']) && $data['scheduled_at']
                ? Carbon::parse($data['scheduled_at'], config('app.timezone'))
                : null;
            $senderId = $template?->sender_id ?? $data['sender_id'] ?? $this->smsSettingsService->current()->sender_id ?? 'CACLOD';
            $status = ($scheduledAt && $scheduledAt->gt(now())) ? 'Scheduled' : 'Draft';
            $metadata = app(\App\Services\Campaign\CampaignMetadataRecorder::class)->smsCreateAttributes($data, $template);

            $campaign = SmsCampaign::create(array_merge([
                'campaign_name' => $data['campaign_name'],
                'campaign_type' => $data['campaign_type'],
                'audience_mode' => $data['audience_mode'],
                'audience_label' => $this->buildAudienceLabel($data),
                'audience_filters' => $this->extractAudienceFilters($data),
                'selected_ca_ids' => $data['audience_mode'] === 'selected_leads'
                    ? array_values(array_map('intval', $data['ca_ids'] ?? []))
                    : null,
                'sender_id' => $senderId,
                'sms_template_id' => $template?->id,
                'message_template' => $template?->body_template ?? $data['message_template'],
                'scheduled_at' => $scheduledAt,
                'status' => $status,
                'total_sms' => $this->smsAlertMappingService->deduplicateLeadsByMobile($recipients)->count(),
                'queued_count' => 0,
                'skipped_count' => 0,
            ], $metadata));

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
        $this->ensureCanSendSms(auth()->user());

        $campaign = $this->find($id);

        if ($campaign->status === 'Processing') {
            throw new InvalidArgumentException('Campaign is already processing.');
        }

        if (! $campaign->sms_template_id) {
            throw new InvalidArgumentException('Select an approved DLT SMS template before sending.');
        }

        $template = $this->smsDltTemplateService->findApproved((int) $campaign->sms_template_id);
        $settings = $this->smsSettingsService->current();
        $this->assertReadyForLiveDispatch($settings, $template);

        $campaign->update(['status' => 'Processing']);

        QueueDispatcher::dispatchOrRun(new ProcessSmsCampaignJob((int) $campaign->id));

        return $campaign->fresh();
    }

    public function runProcess(int $campaignId): void
    {
        DB::transaction(function () use ($campaignId) {
            $campaign = $this->find($campaignId);

            if (! in_array($campaign->status, ['Processing', 'Scheduled'], true)) {
                return;
            }

            $template = $this->smsDltTemplateService->findApproved((int) $campaign->sms_template_id);
            $settings = $this->smsSettingsService->current();
            $this->assertReadyForLiveDispatch($settings, $template);

            $leads = $this->resolveCampaignAudience($campaign);
            $deduped = $this->smsAlertMappingService->deduplicateLeadsByMobile($leads);
            $actor = $campaign->created_by_user_id
                ? User::query()->find($campaign->created_by_user_id)
                : null;
            $employeeId = $actor ? $this->employeeDataScope->resolveEmployeeId($actor) : null;
            $dltTemplateId = $this->smsAlertMappingService->resolveCampaignDltTemplateId($template->dlt_template_id);

            $sentCount = 0;
            $failedCount = 0;
            $pendingCount = 0;

            foreach ($deduped as $lead) {
                $message = $this->smsDltTemplateService->renderBody($template, $lead);
                $mobileError = $this->smsAlertMappingService->leadMobileValidationError($lead->mobile_no);

                if ($mobileError !== null || ! filled(trim($message))) {
                    SmsLog::create([
                        'campaign_id' => $campaign->id,
                        'sms_template_id' => $template->id,
                        'template_name' => $template->template_name,
                        'dlt_template_id' => $dltTemplateId,
                        'ca_id' => $lead->ca_id,
                        'employee_id' => $employeeId,
                        'mobile_no' => $lead->mobile_no,
                        'sender_id' => $template->sender_id,
                        'message' => $message,
                        'sms_status' => SmsLogStatus::FAILED,
                        'error_message' => $mobileError ?? 'Rendered SMS message is empty.',
                        'failed_reason' => $mobileError ?? 'Rendered SMS message is empty.',
                        'queued_at' => now(),
                    ]);
                    $failedCount++;

                    continue;
                }

                $payload = $this->smsAlertMappingService->buildPushPayload(
                    $settings,
                    (string) $lead->mobile_no,
                    $message,
                    $dltTemplateId,
                );

                $log = SmsLog::create([
                    'campaign_id' => $campaign->id,
                    'sms_template_id' => $template->id,
                    'template_name' => $template->template_name,
                    'dlt_template_id' => $dltTemplateId,
                    'ca_id' => $lead->ca_id,
                    'employee_id' => $employeeId,
                    'mobile_no' => $payload['mobileno'],
                    'sender_id' => $template->sender_id,
                    'message' => $message,
                    'sms_status' => SmsLogStatus::PENDING,
                    'queued_at' => now(),
                ]);
                $pendingCount++;

                $result = $this->smsDispatchService->send(
                    $settings,
                    $template->sender_id,
                    (string) $lead->mobile_no,
                    $message,
                    $dltTemplateId,
                );

                $this->smsDispatchService->applyDispatchResult($log, $result);

                if ($result['success']) {
                    $sentCount++;
                } else {
                    $failedCount++;
                }
            }

            $finalStatus = match (true) {
                $sentCount > 0 && $failedCount === 0 => 'Completed',
                $sentCount > 0 => 'Partial',
                default => 'Failed',
            };

            $campaign->update([
                'status' => $finalStatus,
                'total_sms' => $deduped->count(),
                'delivered_count' => $sentCount,
                'failed_count' => $failedCount,
                'queued_count' => $pendingCount,
            ]);

            $this->activityLogService->log(
                'SMS_CAMPAIGN',
                'SMS Campaign Sent',
                (string) $campaign->id,
                $campaign->campaign_name.' · Sent: '.$sentCount.' · Failed: '.$failedCount,
                $actor?->name ?? $actor?->email ?? 'System',
            );
        });
    }

    public function markProcessFailed(int $campaignId, string $message): void
    {
        SmsCampaign::query()->where('id', $campaignId)->update(['status' => 'Failed']);
    }

    private function assertReadyForLiveDispatch(SmsSetting $settings, ?SmsTemplate $template = null): void
    {
        if (! $settings->is_active) {
            throw new InvalidArgumentException('SMS provider is inactive.');
        }

        if (! $settings->isLiveMode()) {
            throw new InvalidArgumentException('SMS provider must be in Live mode to send messages.');
        }

        $validation = $this->smsSettingsService->validateConfiguration();
        if (! $validation['valid']) {
            throw new InvalidArgumentException(implode(' ', $validation['errors']));
        }

        if ($template !== null) {
            $dltTemplateId = $this->smsAlertMappingService->resolveCampaignDltTemplateId($template->dlt_template_id);
            $dltError = $this->smsAlertMappingService->campaignDltTemplateIdValidationError($settings, $dltTemplateId);
            if ($dltError !== null) {
                throw new InvalidArgumentException($dltError);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCampaignTemplate(array $data): ?SmsTemplate
    {
        if (empty($data['sms_template_id'])) {
            return null;
        }

        return $this->smsDltTemplateService->findApproved((int) $data['sms_template_id']);
    }

    private function resolveAudience(array $data): Collection
    {
        $query = $this->employeeDataScope->audienceCaMasterQuery();

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
                ? ($data['scheduled_at'] ? Carbon::parse($data['scheduled_at'], config('app.timezone')) : null)
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
