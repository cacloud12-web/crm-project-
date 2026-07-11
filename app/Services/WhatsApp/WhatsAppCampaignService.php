<?php

namespace App\Services\WhatsApp;

use App\Jobs\WhatsApp\ProcessWhatsAppCampaignJob;
use App\Models\CaMaster;
use App\Models\City;
use App\Models\SourceLead;
use App\Models\State;
use App\Models\WaMessageLog;
use App\Models\User;
use App\Models\WaMessageLogStatus;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppSetting;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use App\Services\Activity\ActivityLogService;
use App\Services\Campaign\CampaignMessageLogProcessor;
use App\Services\Communication\CommunicationEligibilityService;
use App\Services\Concerns\SearchesListings;
use App\Services\Notifications\NotificationService;
use App\Support\Database\SqlAggregate;
use App\Support\Queue\QueueDispatcher;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
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
        private readonly WhatsAppSettingsService $whatsAppSettingsService,
        private readonly WhatsAppTemplateService $whatsAppTemplateService,
        private readonly WhatsAppCloudMappingService $cloudMappingService,
        private readonly WhatsAppLogService $whatsAppLogService,
        private readonly WhatsAppDispatchService $whatsAppDispatchService,
        private readonly RbacService $rbacService,
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function ensureCanSendWhatsapp(?User $user): void
    {
        if (! $this->canSendWhatsapp($user)) {
            throw new AuthorizationException('You do not have permission to send WhatsApp campaigns.');
        }
    }

    public function canSendWhatsapp(?User $user): bool
    {
        return in_array($this->rbacService->roleKey($user), ['admin', 'super_admin', 'manager', 'employee'], true);
    }

    public function ensureCanManageCampaigns(?User $user): void
    {
        if (! $this->canManageCampaigns($user)) {
            throw new AuthorizationException('Only Admin and Super Admin can create or modify WhatsApp campaigns.');
        }
    }

    public function canManageCampaigns(?User $user): bool
    {
        return in_array($this->rbacService->roleKey($user), ['admin', 'super_admin'], true);
    }

    /**
     * @return array{valid: bool, errors: array<int, string>, warnings: array<int, string>}
     */
    public function validatePreparation(array $data): array
    {
        $settings = $this->whatsAppSettingsService->current();
        $errors = [];
        $warnings = [];

        $local = $this->whatsAppSettingsService->validateConfiguration();
        if (! $local['valid']) {
            $errors = array_merge($errors, $local['errors']);
        }

        $template = null;
        if (! empty($data['message_template_id'])) {
            try {
                $template = $this->whatsAppTemplateService->findApproved((int) $data['message_template_id']);
            } catch (\Throwable) {
                $errors[] = 'Selected WhatsApp template is not approved.';
            }
        } else {
            $errors[] = 'Select an approved WhatsApp template.';
        }

        $leads = $this->resolveAudience($data);
        if ($leads->isEmpty()) {
            $errors[] = 'No leads matched the selected audience.';
        }

        if ($template) {
            foreach ($this->cloudMappingService->validateDispatchSettings($template, $settings) as $mappingError) {
                $errors[] = $mappingError;
            }

            foreach ($leads as $lead) {
                foreach ($this->cloudMappingService->validateLeadRecipient($lead) as $mappingError) {
                    $warnings[] = $mappingError;
                }

                if (! $this->cloudMappingService->leadHasActiveAssignment($lead)) {
                    $warnings[] = 'Lead '.$lead->ca_id.' is not assigned to an employee.';
                }
            }
        }

        if ($settings->isLiveMode() && $this->whatsAppSettingsService->integrationStatus($settings) !== WhatsAppSetting::INTEGRATION_INTEGRATED) {
            $warnings[] = 'Live mode is enabled but WhatsApp connection test has not succeeded yet.';
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function previewMessage(int $templateId, int $leadId): array
    {
        $template = $this->whatsAppTemplateService->findApproved($templateId);
        $lead = CaMaster::query()->with(['city', 'state'])->findOrFail($leadId);
        $variables = $this->cloudMappingService->resolveVariables($lead);
        $rendered = $this->cloudMappingService->renderTemplateBody($template->body_template, $variables);
        $payload = $this->cloudMappingService->buildCloudPayload($lead, $template);

        return [
            'lead_id' => $lead->ca_id,
            'firm_name' => $lead->firm_name,
            'mobile_no' => $lead->mobile_no,
            'template_name' => $template->template_name,
            'language_code' => $template->language_code,
            'variables' => $variables,
            'preview' => $rendered,
            'api_payload' => $payload,
        ];
    }

    public function search(array $params = []): array
    {
        return $this->searchListing(
            WhatsAppCampaign::query()            ->withCount([
                'messageLogs as sent_messages_count' => fn ($query) => $query->whereIn('message_status', ['Sent', 'Delivered', 'Read']),
                'messageLogs as delivered_messages_count' => fn ($query) => $query->where('message_status', 'Delivered'),
                'messageLogs as read_messages_count' => fn ($query) => $query->where('message_status', 'Read'),
                'messageLogs as failed_messages_count' => fn ($query) => $query->whereIn('message_status', ['Failed', 'API Error']),
                'messageLogs as pending_messages_count' => fn ($query) => $query->whereIn('message_status', ['Pending', 'Payload Generated', 'Queued']),
                'messageLogs as skipped_messages_count' => fn ($query) => $query->where('message_status', 'Skipped'),
            ]),
            $params,
            'whatsapp_campaigns',
        );
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(WhatsAppCampaign::query(), [], 'whatsapp_campaigns');
    }

    public function searchMessageLogs(array $params = []): array
    {
        return $this->searchListing(
            WaMessageLog::query()->with(['caMaster:ca_id,firm_name,ca_name', 'campaign:id,campaign_name']),
            $params,
            'wa_message_logs',
        );
    }

    public function find(int|string $id): WhatsAppCampaign
    {
        return WhatsAppCampaign::query()
            ->withCount([
                'messageLogs as sent_messages_count' => fn ($query) => $query->whereIn('message_status', ['Sent', 'Delivered', 'Read']),
                'messageLogs as delivered_messages_count' => fn ($query) => $query->where('message_status', 'Delivered'),
                'messageLogs as read_messages_count' => fn ($query) => $query->where('message_status', 'Read'),
                'messageLogs as failed_messages_count' => fn ($query) => $query->whereIn('message_status', ['Failed', 'API Error']),
                'messageLogs as pending_messages_count' => fn ($query) => $query->whereIn('message_status', ['Pending', 'Payload Generated', 'Queued']),
                'messageLogs as skipped_messages_count' => fn ($query) => $query->where('message_status', 'Skipped'),
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
        $this->ensureCanSendWhatsapp(auth()->user());

        return DB::transaction(function () use ($data) {
            $recipients = $this->resolveAudience($data);

            if ($recipients->isEmpty()) {
                throw new InvalidArgumentException('No leads matched the selected audience.');
            }

            $scheduledAt = isset($data['scheduled_at']) && $data['scheduled_at']
                ? Carbon::parse($data['scheduled_at'])
                : null;
            $processNow = ! $scheduledAt || $scheduledAt->lte(now());

            $template = $this->resolveCampaignTemplate($data);
            $settings = $this->whatsAppSettingsService->current();
            $metadata = app(\App\Services\Campaign\CampaignMetadataRecorder::class)->whatsappCreateAttributes($data, $template);

            $campaign = WhatsAppCampaign::create(array_merge([
                'campaign_name' => $data['campaign_name'],
                'campaign_type' => $data['campaign_type'],
                'audience_mode' => $data['audience_mode'],
                'audience_label' => $this->buildAudienceLabel($data),
                'audience_filters' => $this->extractAudienceFilters($data),
                'selected_ca_ids' => $data['audience_mode'] === 'selected_leads'
                    ? array_values(array_map('intval', $data['ca_ids'] ?? []))
                    : null,
                'message_template' => $template
                    ? $template->body_template
                    : $data['message_template'],
                'message_template_id' => $template?->id,
                'template_name' => $template?->template_name,
                'language_code' => $template?->language_code,
                'api_version' => $settings->api_version,
                'scheduled_at' => $scheduledAt,
                'status' => $processNow ? 'Processing' : 'Scheduled',
                'total_messages' => $recipients->count(),
                'queued_count' => 0,
                'skipped_count' => 0,
            ], $metadata));

            if ($template) {
                $this->whatsAppTemplateService->logTemplateSelected($template, auth()->user());
            }

            $this->activityLogService->log(
                'WHATSAPP_CAMPAIGN',
                'Campaign Created',
                (string) $campaign->id,
                $campaign->campaign_name.' · '.$campaign->total_messages.' recipients',
            );

            if ($this->campaignLogProcessor->shouldQueue($recipients->count())) {
                $this->campaignLogProcessor->dispatch('whatsapp', $campaign->id);

                return $this->find($campaign->id);
            }

            $this->populateMessageLogs($campaign, $recipients, $data, $processNow);

            return $this->find($campaign->id);
        });
    }

    public function generateMessageLogs(int $campaignId): void
    {
        DB::transaction(function () use ($campaignId) {
            $campaign = WhatsAppCampaign::query()->findOrFail($campaignId);

            if (WaMessageLog::query()->where('campaign_id', $campaignId)->exists()) {
                if ($campaign->status === 'Processing') {
                    $this->finalizeCampaign($campaign);
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
        bool $queueFinalize = true,
    ): WhatsAppCampaign {
        $template = $campaign->message_template_id
            ? $this->whatsAppTemplateService->findApproved((int) $campaign->message_template_id)
            : null;

        $settings = $this->whatsAppSettingsService->current();

        if ($template) {
            $settingsErrors = $this->cloudMappingService->validateDispatchSettings($template, $settings);
            if ($settingsErrors !== []) {
                throw new InvalidArgumentException(implode(' ', $settingsErrors));
            }
        }

        $queuedCount = 0;
        $skippedCount = 0;
        $skipSummary = [];

        foreach ($recipients as $lead) {
            $eligibility = $this->whatsAppLogService->assessEligibilityForCampaign($lead);

            if ($template) {
                $leadErrors = $this->cloudMappingService->validateLeadRecipient($lead);
                if ($leadErrors !== []) {
                    $eligibility = ['eligible' => false, 'skip_reason' => implode(' ', $leadErrors)];
                }
            }

            if ($template && $eligibility['eligible']) {
                $this->whatsAppLogService->storeMappedPayload($campaign, $lead, $template, true);
                $queuedCount++;

                continue;
            }

            if ($template) {
                $this->whatsAppLogService->storeMappedPayload(
                    $campaign,
                    $lead,
                    $template,
                    false,
                    $eligibility['skip_reason'] ?? 'Not eligible',
                );
                $skippedCount++;
                $reason = $eligibility['skip_reason'] ?? 'Not eligible';
                $skipSummary[$reason] = ($skipSummary[$reason] ?? 0) + 1;

                continue;
            }

            if ($eligibility['eligible']) {
                WaMessageLog::create([
                    'campaign_id' => $campaign->id,
                    'ca_id' => $lead->ca_id,
                    'employee_id' => $this->cloudMappingService->resolveEmployeeId($lead),
                    'mobile_no' => $lead->mobile_no,
                    'message' => $this->renderMessage($data['message_template'], $lead),
                    'message_status' => config('whatsapp_cloud.log_statuses.pending', WaMessageLogStatus::PENDING),
                    'queued_at' => now(),
                ]);
                $queuedCount++;

                continue;
            }

            WaMessageLog::create([
                'campaign_id' => $campaign->id,
                'ca_id' => $lead->ca_id,
                'employee_id' => $this->cloudMappingService->resolveEmployeeId($lead),
                'mobile_no' => $lead->mobile_no,
                'message' => $this->renderMessage($data['message_template'], $lead),
                'message_status' => config('whatsapp_cloud.log_statuses.skipped', 'Skipped'),
                'failed_reason' => $eligibility['skip_reason'],
                'error_message' => $eligibility['skip_reason'],
            ]);
            $skippedCount++;
            $skipSummary[$eligibility['skip_reason']] = ($skipSummary[$eligibility['skip_reason']] ?? 0) + 1;
        }

        $campaign->update([
            'queued_count' => $queuedCount,
            'skipped_count' => $skippedCount,
            'payload_generated_at' => now(),
        ]);

        if ($processNow) {
            if ($queueFinalize) {
                QueueDispatcher::dispatchOrRun(new ProcessWhatsAppCampaignJob((int) $campaign->id));
            } else {
                $this->finalizeCampaign($campaign->fresh());
            }
        }

        $campaign = $campaign->fresh();

        $this->activityLogService->log(
            'WHATSAPP_CAMPAIGN',
            'WhatsApp Campaign Create',
            (string) $campaign->id,
            $campaign->campaign_name.' · '.$campaign->total_messages.' recipients · '.$campaign->audience_label,
        );

        $this->whatsAppLogService->logPayloadGenerated($campaign, $queuedCount);
        $this->logComplianceSkips($campaign, $skipSummary);

        return $this->find($campaign->id);
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
        $this->ensureCanSendWhatsapp(auth()->user());

        $campaign = WhatsAppCampaign::query()->findOrFail($id);

        if (! in_array($campaign->status, ['Scheduled', 'Draft', 'Payload Generated'], true)) {
            throw new InvalidArgumentException('Campaign has already been processed.');
        }

        $settings = $this->whatsAppSettingsService->current();
        if ($settings->isLiveMode()) {
            $this->whatsAppSettingsService->assertReadyForLiveDispatch($settings);
        }

        $campaign->update(['status' => 'Processing']);

        QueueDispatcher::dispatchOrRun(new ProcessWhatsAppCampaignJob((int) $campaign->id));

        return $campaign->fresh();
    }

    public function runProcess(int $campaignId): void
    {
        DB::transaction(function () use ($campaignId) {
            $campaign = WhatsAppCampaign::query()->findOrFail($campaignId);

            if (! WaMessageLog::query()->where('campaign_id', $campaignId)->exists()) {
                $data = $this->campaignDataFromModel($campaign);
                $recipients = $this->resolveRecipients($data);
                $this->populateMessageLogs($campaign, $recipients, $data, false, false);
                $campaign = $campaign->fresh();
            }

            if (in_array($campaign->status, ['Processing', 'Payload Generated'], true)) {
                $this->finalizeCampaign($campaign);
                $campaign = $campaign->fresh();
            }

            $this->whatsAppLogService->logCampaignProcessed($campaign);

            $this->notificationService->campaignCompleted(
                'whatsapp',
                $campaign->campaign_name,
                (int) ($campaign->total_messages ?? 0),
                (int) ($campaign->delivered_count ?? 0),
                $campaign->id,
            );
        });
    }

    public function markProcessFailed(int $campaignId, string $message): void
    {
        WhatsAppCampaign::query()->where('id', $campaignId)->update(['status' => 'Failed']);
    }

    public function dashboardMetrics(): array
    {
        $statusCounts = WaMessageLog::query()
            ->selectRaw(SqlAggregate::countFilter('*', "message_status = 'Delivered'").' as delivered')
            ->selectRaw(SqlAggregate::countFilter('*', "message_status IN ('Failed', 'API Error')").' as failed')
            ->selectRaw(SqlAggregate::countFilter('*', "message_status IN ('Pending', 'Payload Generated', 'Queued')").' as queued')
            ->selectRaw(SqlAggregate::countFilter('*', "message_status IN ('Sent', 'Delivered', 'Read')").' as sent')
            ->selectRaw('COUNT(*) as total')
            ->first();

        return [
            'whatsapp_campaigns_total' => WhatsAppCampaign::query()->count(),
            'whatsapp_messages_total' => (int) ($statusCounts->total ?? 0),
            'whatsapp_delivered' => (int) ($statusCounts->delivered ?? 0),
            'whatsapp_failed' => (int) ($statusCounts->failed ?? 0),
            'whatsapp_queued' => (int) ($statusCounts->queued ?? 0),
            'whatsapp_sent' => (int) ($statusCounts->sent ?? 0),
        ];
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

    private function renderMessage(string $template, CaMaster $lead): string
    {
        return $this->cloudMappingService->renderTemplateBody(
            $template,
            $this->cloudMappingService->resolveVariables($lead),
        );
    }

    private function finalizeCampaign(WhatsAppCampaign $campaign): void
    {
        $settings = $this->whatsAppSettingsService->current();

        if (! $settings->isLiveMode()) {
            $campaign->update([
                'status' => 'Completed',
                'payload_generated_at' => $campaign->payload_generated_at ?? now(),
            ]);
            $this->syncCampaignStats((int) $campaign->id);

            return;
        }

        $this->whatsAppSettingsService->assertReadyForLiveDispatch($settings);

        $logs = WaMessageLog::query()->where('campaign_id', $campaign->id)->get();
        $dispatchableStatuses = [
            config('whatsapp_cloud.log_statuses.pending', WaMessageLogStatus::PENDING),
            config('whatsapp_cloud.log_statuses.payload_generated', WaMessageLogStatus::PAYLOAD_GENERATED),
        ];
        $skipped = $logs->where('message_status', config('whatsapp_cloud.log_statuses.skipped', WaMessageLogStatus::SKIPPED))->count();

        $sentCount = 0;
        $failedCount = 0;

        foreach ($logs as $log) {
            if (! in_array($log->message_status, $dispatchableStatuses, true) || ! is_array($log->api_payload)) {
                continue;
            }

            $result = $this->whatsAppDispatchService->send($settings, $log->api_payload);
            $this->whatsAppDispatchService->applyDispatchResult($log, $result);

            if ($result['success']) {
                $sentCount++;
            } else {
                $failedCount++;
            }
        }

        if ($sentCount > 0) {
            $this->whatsAppSettingsService->recordSuccessfulSend($settings);
        }

        $finalStatus = match (true) {
            $sentCount > 0 && $failedCount === 0 && $skipped === 0 => 'Completed',
            $sentCount > 0 => 'Partial',
            default => 'Failed',
        };

        $campaign->update([
            'status' => $finalStatus,
            'payload_generated_at' => $campaign->payload_generated_at ?? now(),
        ]);

        $this->syncCampaignStats((int) $campaign->id);
    }

    public function syncCampaignStats(int $campaignId): void
    {
        $campaign = WhatsAppCampaign::query()->find($campaignId);
        if (! $campaign) {
            return;
        }

        $baseQuery = WaMessageLog::query()->where('campaign_id', $campaignId);

        $campaign->update([
            'queued_count' => (clone $baseQuery)->whereIn('message_status', [
                WaMessageLogStatus::PENDING,
                WaMessageLogStatus::PAYLOAD_GENERATED,
                WaMessageLogStatus::QUEUED,
            ])->count(),
            'skipped_count' => (clone $baseQuery)->where('message_status', WaMessageLogStatus::SKIPPED)->count(),
            'delivered_count' => (clone $baseQuery)->where('message_status', WaMessageLogStatus::DELIVERED)->count(),
            'failed_count' => (clone $baseQuery)->whereIn('message_status', [
                WaMessageLogStatus::FAILED,
                WaMessageLogStatus::API_ERROR,
            ])->count(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCampaignTemplate(array $data): ?\App\Models\MessageTemplate
    {
        if (! empty($data['message_template_id'])) {
            return $this->whatsAppTemplateService->findApproved((int) $data['message_template_id']);
        }

        if (! empty($data['template_name'])) {
            return $this->whatsAppTemplateService->findByName(
                (string) $data['template_name'],
                (string) ($data['language_code'] ?? 'en'),
            );
        }

        return null;
    }

    /**
     * Preview Cloud API payload for a single lead (mapping only).
     *
     * @return array<string, mixed>
     */
    public function previewPayload(int $campaignId, int $caId): array
    {
        $campaign = $this->find($campaignId);
        $lead = CaMaster::query()->findOrFail($caId);
        $template = $campaign->message_template_id
            ? $this->whatsAppTemplateService->findApproved((int) $campaign->message_template_id)
            : null;

        if (! $template) {
            throw new InvalidArgumentException('Campaign does not have an approved Cloud API template mapped.');
        }

        $this->cloudMappingService->assertCampaignMappable($lead, $template);

        return $this->cloudMappingService->buildCloudPayload($lead, $template);
    }

    public function update(WhatsAppCampaign $campaign, array $data): WhatsAppCampaign
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
            'WHATSAPP_CAMPAIGN',
            'Campaign Update',
            (string) $campaign->id,
            $campaign->campaign_name,
        );

        return $campaign->fresh();
    }

    public function delete(WhatsAppCampaign $campaign): void
    {
        $this->ensureCanManageCampaigns(auth()->user());
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
