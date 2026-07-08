<?php

namespace App\Services\Email;

use App\Jobs\Email\ProcessEmailCampaignDeliveryJob;
use App\Models\CaMaster;
use App\Models\City;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\SourceLead;
use App\Models\State;
use App\Services\Activity\ActivityLogService;
use App\Services\Campaign\CampaignMessageLogProcessor;
use App\Services\Communication\CommunicationEligibilityService;
use App\Services\Concerns\SearchesListings;
use App\Services\Notifications\NotificationService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Support\Database\SqlAggregate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EmailCampaignService
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
        private readonly GoDaddyMailService $goDaddyMailService,
        private readonly EmailSettingsService $emailSettingsService,
        private readonly EmailSmtpDispatchService $smtpDispatchService,
        private readonly EmailTemplateService $emailTemplateService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly EmailRecipientValidationService $recipientValidationService,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(EmailCampaign::query(), $params, 'email_campaigns');
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(EmailCampaign::query(), [], 'email_campaigns');
    }

    public function searchEmailLogs(array $params = []): array
    {
        return $this->searchListing(
            EmailLog::query()->with(['caMaster:ca_id,firm_name', 'campaign:id,campaign_name,subject']),
            $params,
            'email_logs',
        );
    }

    public function find(int|string $id): EmailCampaign
    {
        return EmailCampaign::query()
            ->withCount([
                'emailLogs as delivered_emails_count' => fn ($query) => $query->whereIn('email_status', ['Delivered', EmailRecipientValidationService::STATUS_SENT]),
                'emailLogs as failed_emails_count' => fn ($query) => $query->where('email_status', EmailRecipientValidationService::STATUS_FAILED),
                'emailLogs as queued_emails_count' => fn ($query) => $query->where('email_status', EmailRecipientValidationService::STATUS_QUEUED),
            ])
            ->findOrFail($id);
    }

    public function emailLogs(?int $campaignId = null, int $limit = 500): Collection
    {
        $params = $campaignId ? ['campaign_id' => $campaignId] : [];
        if ($limit && empty($params['page'])) {
            $params['per_page'] = min($limit, 500);
        }

        return collect($this->searchEmailLogs($params)['items']);
    }

    public function payloadPreview(int|string $id): array
    {
        $campaign = $this->find($id);
        $data = array_merge([
            'audience_mode' => $campaign->audience_mode,
            'ca_ids' => $campaign->selected_ca_ids ?? [],
        ], $campaign->audience_filters ?? []);

        $leads = $this->resolveAudience($data);
        $mailObjects = $this->goDaddyMailService->buildCampaignMailObjects($campaign, $leads);

        return [
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->campaign_name,
            'settings' => $this->emailSettingsService->toPublicArray(),
            'mail_objects' => $mailObjects,
            'valid_count' => collect($mailObjects)->where('valid', true)->count(),
            'invalid_count' => collect($mailObjects)->where('valid', false)->count(),
            'statistics' => $this->campaignStatistics($campaign),
            'dispatch' => 'validated_preview',
        ];
    }

    public function create(array $data): EmailCampaign
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

            $template = $this->resolveCampaignTemplate($data);
            $metadata = app(\App\Services\Campaign\CampaignMetadataRecorder::class)->emailCreateAttributes($data);

            $campaign = EmailCampaign::create(array_merge([
                'campaign_name' => $data['campaign_name'],
                'campaign_type' => $data['campaign_type'],
                'audience_mode' => $data['audience_mode'],
                'audience_label' => $this->buildAudienceLabel($data),
                'audience_filters' => $this->extractAudienceFilters($data),
                'selected_ca_ids' => $data['audience_mode'] === 'selected_leads'
                    ? array_values(array_map('intval', $data['ca_ids'] ?? []))
                    : null,
                'subject' => $template ? $template->subject : $data['subject'],
                'body_template' => $template ? $template->body : $data['body_template'],
                'email_template_id' => $template?->id,
                'scheduled_at' => $scheduledAt,
                'status' => $processNow ? 'Processing' : 'Scheduled',
                'total_emails' => $recipients->count(),
                'queued_count' => 0,
                'skipped_count' => 0,
            ], $metadata));

            if ($this->campaignLogProcessor->shouldQueue($recipients->count())) {
                $this->campaignLogProcessor->dispatch('email', $campaign->id);

                return $campaign->fresh();
            }

            $now = now();
            $stats = $this->emptyRecipientStats();
            $seenEmails = [];
            $skipSummary = [];

            foreach ($recipients as $lead) {
                $eligibility = $this->eligibilityService->assessForCampaign($lead, CommunicationEligibilityService::CHANNEL_EMAIL);
                $this->createMappedLog(
                    $campaign,
                    $lead,
                    (string) $campaign->subject,
                    (string) $campaign->body_template,
                    $eligibility,
                    $now,
                    $stats,
                    $seenEmails,
                    $skipSummary,
                );
            }

            $this->persistRecipientStats($campaign, $stats);

            if ($processNow) {
                $this->queueCampaignDelivery($campaign);
            }

            $campaign = $campaign->fresh();

            $this->activityLogService->log(
                'EMAIL_CAMPAIGN',
                'Email Campaign Created',
                (string) $campaign->id,
                $campaign->campaign_name.' · '.$campaign->total_emails.' recipients · '.$campaign->audience_label,
            );

            $this->logComplianceSkips($campaign, $skipSummary);

            return $campaign;
        });
    }

    public function generateMessageLogs(int $campaignId): void
    {
        DB::transaction(function () use ($campaignId) {
            $campaign = EmailCampaign::query()->findOrFail($campaignId);
            $actor = $campaign->created_by_user_id
                ? \App\Models\User::query()->find($campaign->created_by_user_id)
                : auth()->user();

            if (EmailLog::query()->where('campaign_id', $campaignId)->exists()) {
                if (in_array($campaign->status, ['Processing', 'Scheduled', 'Draft'], true)) {
                    $this->queueCampaignDelivery($campaign);
                }

                return;
            }

            $data = array_merge([
                'audience_mode' => $campaign->audience_mode,
                'ca_ids' => $campaign->selected_ca_ids ?? [],
                'subject' => $campaign->subject,
                'body_template' => $campaign->body_template,
            ], $campaign->audience_filters ?? []);

            $recipients = $this->resolveAudience($data, $actor);

            if ($recipients->isEmpty()) {
                throw new InvalidArgumentException('No leads matched the campaign audience during background processing.');
            }

            $processNow = ! $campaign->scheduled_at || $campaign->scheduled_at->lte(now());
            $now = now();
            $stats = $this->emptyRecipientStats();
            $seenEmails = [];
            $skipSummary = [];

            foreach ($recipients as $lead) {
                $eligibility = $this->eligibilityService->assessForCampaign($lead, CommunicationEligibilityService::CHANNEL_EMAIL);
                $this->createMappedLog(
                    $campaign,
                    $lead,
                    (string) $campaign->subject,
                    (string) $campaign->body_template,
                    $eligibility,
                    $now,
                    $stats,
                    $seenEmails,
                    $skipSummary,
                    $actor,
                );
            }

            $this->persistRecipientStats($campaign, $stats);

            if ($processNow) {
                $this->queueCampaignDelivery($campaign);
            }

            $campaign = $campaign->fresh();

            $this->activityLogService->log(
                'EMAIL_CAMPAIGN',
                'Email Campaign Created',
                (string) $campaign->id,
                $campaign->campaign_name.' · '.$campaign->total_emails.' recipients · '.$campaign->audience_label,
            );

            $this->logComplianceSkips($campaign, $skipSummary);
        });
    }

    public function markLogGenerationFailed(int $campaignId): void
    {
        EmailCampaign::query()->where('id', $campaignId)->update(['status' => 'Failed']);
    }

    public function process(int|string $id): EmailCampaign
    {
        return DB::transaction(function () use ($id) {
            $campaign = EmailCampaign::query()->findOrFail($id);

            if (! in_array($campaign->status, ['Scheduled', 'Draft'], true)) {
                throw new InvalidArgumentException('Campaign has already been processed.');
            }

            if (! $this->campaignHasMessageLogs($campaign)) {
                if ($this->campaignLogProcessor->shouldQueue((int) $campaign->total_emails)) {
                    $campaign->update(['status' => 'Processing']);
                    $this->campaignLogProcessor->dispatch('email', $campaign->id);

                    return $campaign->fresh();
                }

                $this->generateMessageLogs($campaign->id);

                return $campaign->fresh();
            }

            $campaign->update(['status' => 'Processing']);
            $this->queueCampaignDelivery($campaign);
            $campaign = $campaign->fresh();

            $this->notificationService->campaignCompleted(
                'email',
                $campaign->campaign_name,
                (int) ($campaign->total_messages ?? 0),
                (int) ($campaign->delivered_count ?? 0),
                $campaign->id,
            );

            return $campaign;
        });
    }

    public function retryFailed(int|string $id): EmailCampaign
    {
        return DB::transaction(function () use ($id) {
            $campaign = EmailCampaign::query()->findOrFail($id);

            $failedCount = EmailLog::query()
                ->where('campaign_id', $campaign->id)
                ->where('email_status', EmailRecipientValidationService::STATUS_FAILED)
                ->count();

            if ($failedCount === 0) {
                throw new InvalidArgumentException('No failed messages to retry.');
            }

            EmailLog::query()
                ->where('campaign_id', $campaign->id)
                ->where('email_status', EmailRecipientValidationService::STATUS_FAILED)
                ->update([
                    'email_status' => EmailRecipientValidationService::STATUS_QUEUED,
                    'failed_reason' => null,
                    'error_message' => null,
                ]);

            $campaign->update([
                'status' => 'Processing',
                'delivery_completed_at' => null,
                'delivery_dispatch_token' => null,
                'delivery_started_at' => null,
            ]);

            $this->queueCampaignDelivery($campaign->fresh());

            $this->activityLogService->log(
                'EMAIL_CAMPAIGN',
                'Email Campaign Retry Failed Messages',
                (string) $campaign->id,
                $campaign->campaign_name.' · '.$failedCount.' messages re-queued',
            );

            return $campaign->fresh();
        });
    }

    public function dashboardMetrics(): array
    {
        $statusCounts = EmailLog::query()
            ->selectRaw(SqlAggregate::countFilter('*', "email_status IN ('Delivered', '".EmailRecipientValidationService::STATUS_SENT."')").' as delivered')
            ->selectRaw(SqlAggregate::countFilter('*', "email_status = '".EmailRecipientValidationService::STATUS_FAILED."'").' as failed')
            ->selectRaw(SqlAggregate::countFilter('*', "email_status = '".EmailRecipientValidationService::STATUS_QUEUED."'").' as queued')
            ->selectRaw('COUNT(*) as total')
            ->first();

        return [
            'email_campaigns_total' => EmailCampaign::query()->count(),
            'email_messages_total' => (int) ($statusCounts->total ?? 0),
            'email_delivered' => (int) ($statusCounts->delivered ?? 0),
            'email_failed' => (int) ($statusCounts->failed ?? 0),
            'email_queued' => (int) ($statusCounts->queued ?? 0),
            'email_replies_received' => EmailLog::query()
                ->where('email_status', EmailRecipientValidationService::STATUS_REPLY_RECEIVED)
                ->count(),
            'email_unread_replies' => \App\Models\EmailInboundMessage::query()
                ->where('direction', \App\Models\EmailInboundMessage::DIRECTION_INBOUND)
                ->where('is_read', false)
                ->count(),
            'email_today_replies' => \App\Models\EmailInboundMessage::query()
                ->where('direction', \App\Models\EmailInboundMessage::DIRECTION_INBOUND)
                ->where('received_at', '>=', now()->startOfDay())
                ->count(),
        ];
    }

    private function resolveAudience(array $data, ?\App\Models\User $user = null): Collection
    {
        $user ??= auth()->user();
        $employeeId = $this->employeeDataScope->scopedEmployeeId($user);
        $query = $this->employeeDataScope->scopeCaMasterQuery(CaMaster::query(), $employeeId);

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

    private function createMappedLog(
        EmailCampaign $campaign,
        CaMaster $lead,
        string $subjectTemplate,
        string $bodyTemplate,
        array $eligibility,
        $now,
        array &$stats,
        array &$seenEmails,
        array &$skipSummary,
        ?\App\Models\User $actor = null,
    ): void {
        $actor ??= auth()->user();
        $employeeId = $this->employeeDataScope->resolveEmployeeId($actor);
        $senderName = $actor?->name ?? $actor?->email ?? 'CA Cloud Desk';
        $settings = $this->emailSettingsService->current();
        $subject = $this->goDaddyMailService->renderTemplate($subjectTemplate, $lead, $senderName);
        $body = $this->goDaddyMailService->renderTemplate($bodyTemplate, $lead, $senderName);
        $rawEmail = $lead->email_id;

        if (! $eligibility['eligible']) {
            $this->createFailureLog(
                $campaign,
                $lead,
                $employeeId,
                $rawEmail,
                $subject,
                $body,
                EmailRecipientValidationService::STATUS_SKIPPED,
                $this->eligibilityService->skipReasonLabel((string) $eligibility['skip_reason']),
            );
            $stats['skipped']++;
            $skipSummary[$eligibility['skip_reason']] = ($skipSummary[$eligibility['skip_reason']] ?? 0) + 1;

            return;
        }

        $validation = $this->recipientValidationService->validate($rawEmail, checkMx: true, seenInCampaign: $seenEmails);
        if (! $validation['valid']) {
            $this->createFailureLog(
                $campaign,
                $lead,
                $employeeId,
                $rawEmail,
                $subject,
                $body,
                $validation['status'],
                (string) $validation['reason'],
            );
            $this->incrementValidationStat($stats, $validation['status']);

            return;
        }

        EmailLog::create([
            'campaign_id' => $campaign->id,
            'email_setting_id' => $settings->id,
            'ca_id' => $lead->ca_id,
            'employee_id' => $employeeId,
            'recipient_email' => trim((string) $rawEmail),
            'subject' => $subject,
            'body' => $body,
            'is_html' => true,
            'email_status' => EmailRecipientValidationService::STATUS_QUEUED,
            'queued_at' => $now,
        ]);
        $stats['valid']++;
        $stats['queued']++;
    }

    public function queueCampaignDelivery(EmailCampaign $campaign): void
    {
        $campaign = $campaign->fresh();

        if ($campaign->delivery_completed_at) {
            return;
        }

        if ($campaign->delivery_dispatch_token && $campaign->delivery_started_at && ! $campaign->delivery_completed_at) {
            return;
        }

        if (! $this->campaignHasMessageLogs($campaign)) {
            if ((int) $campaign->total_emails > 0) {
                $this->campaignLogProcessor->dispatch('email', $campaign->id);
            }

            return;
        }

        $token = (string) Str::uuid();

        $campaign->update([
            'status' => 'Processing',
            'delivery_dispatch_token' => $token,
            'delivery_started_at' => now(),
        ]);

        ProcessEmailCampaignDeliveryJob::dispatch($campaign->id, $token);
    }

    public function runDelivery(int $campaignId, string $dispatchToken): void
    {
        $campaign = EmailCampaign::query()->findOrFail($campaignId);

        if ($campaign->delivery_dispatch_token !== $dispatchToken) {
            return;
        }

        if ($campaign->delivery_completed_at) {
            return;
        }

        if (! $this->campaignHasMessageLogs($campaign)) {
            $campaign->update([
                'delivery_dispatch_token' => null,
                'delivery_started_at' => null,
            ]);
            $this->campaignLogProcessor->dispatch('email', $campaignId);

            return;
        }

        $settings = $this->emailSettingsService->current();

        if ($settings->isLiveMode()) {
            $this->deliverViaSmtp($campaign, $settings);
        } else {
            $this->simulateDelivery($campaign);
        }

        $this->syncCampaignStatisticsFromLogs($campaign->fresh());
    }

    public function markDeliveryFailed(int $campaignId, string $message): void
    {
        EmailCampaign::query()->where('id', $campaignId)->update([
            'status' => 'Failed',
            'delivery_completed_at' => now(),
        ]);

        $this->activityLogService->log(
            'EMAIL_CAMPAIGN',
            'Email Campaign Delivery Failed',
            (string) $campaignId,
            $message,
        );
    }

    /**
     * @return array<string, int>
     */
    public function campaignStatistics(EmailCampaign $campaign): array
    {
        return [
            'total_leads' => (int) $campaign->total_emails,
            'valid_emails' => (int) ($campaign->valid_emails_count ?? 0),
            'invalid_emails' => (int) ($campaign->invalid_emails_count ?? 0),
            'duplicate_emails' => (int) ($campaign->duplicate_emails_count ?? 0),
            'invalid_domain' => (int) ($campaign->invalid_domain_count ?? 0),
            'emails_sent' => (int) ($campaign->sent_count ?? $campaign->delivered_count ?? 0),
            'failed' => (int) ($campaign->failed_count ?? 0),
            'skipped' => (int) ($campaign->skipped_count ?? 0),
        ];
    }

    private function deliverViaSmtp(EmailCampaign $campaign, \App\Models\EmailSetting $settings): void
    {
        $logs = EmailLog::query()
            ->where('campaign_id', $campaign->id)
            ->where('email_status', EmailRecipientValidationService::STATUS_QUEUED)
            ->orderBy('id')
            ->get();

        $sentInRun = [];

        foreach ($logs as $log) {
            $normalized = EmailRecipientValidationService::normalize($log->recipient_email);

            if (isset($sentInRun[$normalized])) {
                $log->update([
                    'email_status' => EmailRecipientValidationService::STATUS_DUPLICATE,
                    'failed_reason' => 'Duplicate email address in this campaign execution.',
                    'error_message' => 'Duplicate email address in this campaign execution.',
                ]);

                continue;
            }

            $validation = $this->recipientValidationService->validate($log->recipient_email, checkMx: true);
            if (! $validation['valid']) {
                $log->update([
                    'email_status' => $validation['status'],
                    'failed_reason' => $validation['reason'],
                    'error_message' => $validation['reason'],
                ]);

                continue;
            }

            $log->update([
                'email_status' => EmailRecipientValidationService::STATUS_PROCESSING,
                'email_setting_id' => $settings->id,
            ]);

            $result = $this->smtpDispatchService->send(
                $settings,
                (string) $log->recipient_email,
                (string) $log->subject,
                (string) $log->body,
            );

            $this->smtpDispatchService->applyDispatchResult($log, $result);

            if ($result['success']) {
                $sentInRun[$normalized] = true;
            }
        }

        $campaign->update(['delivery_completed_at' => now()]);
        $this->finalizeCampaignStatus($campaign);
    }

    private function resolveCampaignTemplate(array $data): ?\App\Models\EmailTemplate
    {
        if (empty($data['email_template_id'])) {
            return null;
        }

        return $this->emailTemplateService->findActive((int) $data['email_template_id']);
    }

    private function simulateDelivery(EmailCampaign $campaign): void
    {
        $logs = EmailLog::query()
            ->where('campaign_id', $campaign->id)
            ->where('email_status', EmailRecipientValidationService::STATUS_QUEUED)
            ->orderBy('id')
            ->get();

        $now = now();
        $sentInRun = [];

        foreach ($logs as $index => $log) {
            $normalized = EmailRecipientValidationService::normalize($log->recipient_email);

            if (isset($sentInRun[$normalized])) {
                $log->update([
                    'email_status' => EmailRecipientValidationService::STATUS_DUPLICATE,
                    'failed_reason' => 'Duplicate email address in this campaign execution.',
                    'error_message' => 'Duplicate email address in this campaign execution.',
                ]);

                continue;
            }

            $validation = $this->recipientValidationService->validate($log->recipient_email, checkMx: true);
            if (! $validation['valid']) {
                $log->update([
                    'email_status' => $validation['status'],
                    'failed_reason' => $validation['reason'],
                    'error_message' => $validation['reason'],
                ]);

                continue;
            }

            $log->update([
                'email_status' => EmailRecipientValidationService::STATUS_PROCESSING,
                'sent_at' => $now->copy()->addMilliseconds($index * 5),
            ]);

            $shouldFail = random_int(1, 100) > 96;

            if ($shouldFail) {
                $log->update([
                    'email_status' => EmailRecipientValidationService::STATUS_FAILED,
                    'failed_reason' => 'Simulation: delivery failed',
                    'error_message' => 'Simulation: delivery failed',
                ]);

                continue;
            }

            $log->update([
                'email_status' => EmailRecipientValidationService::STATUS_SENT,
                'delivered_at' => $now->copy()->addMilliseconds(($index * 5) + 10),
            ]);
            $sentInRun[$normalized] = true;
        }

        $campaign->update(['delivery_completed_at' => now()]);
        $this->finalizeCampaignStatus($campaign);
    }

    public function update(EmailCampaign $campaign, array $data): EmailCampaign
    {
        if ($campaign->status === 'Processing') {
            throw new InvalidArgumentException('Cannot edit a campaign while it is processing.');
        }

        $campaign->update([
            'campaign_name' => $data['campaign_name'] ?? $campaign->campaign_name,
            'subject' => $data['subject'] ?? $campaign->subject,
            'body_template' => $data['body_template'] ?? $data['message_template'] ?? $campaign->body_template,
            'scheduled_at' => array_key_exists('scheduled_at', $data)
                ? ($data['scheduled_at'] ? Carbon::parse($data['scheduled_at']) : null)
                : $campaign->scheduled_at,
        ]);

        $this->activityLogService->log(
            'EMAIL_CAMPAIGN',
            'Campaign Update',
            (string) $campaign->id,
            $campaign->campaign_name,
        );

        return $campaign->fresh();
    }

    public function delete(EmailCampaign $campaign): void
    {
        if ($campaign->status === 'Processing') {
            throw new InvalidArgumentException('Cannot delete a campaign while it is processing.');
        }

        $name = $campaign->campaign_name;
        $id = (string) $campaign->id;

        DB::transaction(function () use ($campaign) {
            EmailLog::query()->where('campaign_id', $campaign->id)->delete();
            $campaign->delete();
        });

        $this->activityLogService->log('EMAIL_CAMPAIGN', 'Campaign Delete', $id, $name);
    }

    private function createFailureLog(
        EmailCampaign $campaign,
        CaMaster $lead,
        ?int $employeeId,
        ?string $rawEmail,
        string $subject,
        string $body,
        string $status,
        string $reason,
    ): void {
        EmailLog::create([
            'campaign_id' => $campaign->id,
            'ca_id' => $lead->ca_id,
            'employee_id' => $employeeId,
            'recipient_email' => filled($rawEmail) ? trim((string) $rawEmail) : '',
            'subject' => $subject,
            'body' => $body,
            'email_status' => $status,
            'failed_reason' => $reason,
            'error_message' => $reason,
        ]);
    }

    /**
     * @return array{valid: int, invalid: int, duplicate: int, invalid_domain: int, queued: int, skipped: int}
     */
    private function emptyRecipientStats(): array
    {
        return [
            'valid' => 0,
            'invalid' => 0,
            'duplicate' => 0,
            'invalid_domain' => 0,
            'queued' => 0,
            'skipped' => 0,
        ];
    }

    /**
     * @param  array{valid: int, invalid: int, duplicate: int, invalid_domain: int, queued: int, skipped: int}  $stats
     */
    private function persistRecipientStats(EmailCampaign $campaign, array $stats): void
    {
        $campaign->update([
            'valid_emails_count' => $stats['valid'],
            'invalid_emails_count' => $stats['invalid'],
            'duplicate_emails_count' => $stats['duplicate'],
            'invalid_domain_count' => $stats['invalid_domain'],
            'queued_count' => $stats['queued'],
            'skipped_count' => $stats['skipped'],
        ]);
    }

    private function incrementValidationStat(array &$stats, string $status): void
    {
        match ($status) {
            EmailRecipientValidationService::STATUS_INVALID_EMAIL => $stats['invalid']++,
            EmailRecipientValidationService::STATUS_INVALID_DOMAIN => $stats['invalid_domain']++,
            EmailRecipientValidationService::STATUS_DUPLICATE => $stats['duplicate']++,
            EmailRecipientValidationService::STATUS_SKIPPED => $stats['skipped']++,
            default => $stats['invalid']++,
        };
    }

    private function syncCampaignStatisticsFromLogs(EmailCampaign $campaign): void
    {
        $counts = EmailLog::query()
            ->where('campaign_id', $campaign->id)
            ->selectRaw(SqlAggregate::countFilter('*', "email_status IN ('Sent', 'Delivered')").' as sent')
            ->selectRaw(SqlAggregate::countFilter('*', "email_status = '".EmailRecipientValidationService::STATUS_FAILED."'").' as failed')
            ->selectRaw(SqlAggregate::countFilter('*', "email_status = '".EmailRecipientValidationService::STATUS_SKIPPED."'").' as skipped')
            ->selectRaw(SqlAggregate::countFilter('*', "email_status = '".EmailRecipientValidationService::STATUS_INVALID_EMAIL."'").' as invalid_email')
            ->selectRaw(SqlAggregate::countFilter('*', "email_status = '".EmailRecipientValidationService::STATUS_INVALID_DOMAIN."'").' as invalid_domain')
            ->selectRaw(SqlAggregate::countFilter('*', "email_status = '".EmailRecipientValidationService::STATUS_DUPLICATE."'").' as duplicate')
            ->selectRaw(SqlAggregate::countFilter('*', "email_status = '".EmailRecipientValidationService::STATUS_QUEUED."'").' as queued')
            ->first();

        $campaign->update([
            'sent_count' => (int) ($counts->sent ?? 0),
            'delivered_count' => (int) ($counts->sent ?? 0),
            'failed_count' => (int) ($counts->failed ?? 0),
            'skipped_count' => (int) ($counts->skipped ?? 0),
            'invalid_emails_count' => (int) ($counts->invalid_email ?? 0),
            'invalid_domain_count' => (int) ($counts->invalid_domain ?? 0),
            'duplicate_emails_count' => (int) ($counts->duplicate ?? 0),
            'valid_emails_count' => EmailLog::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('email_status', [
                    EmailRecipientValidationService::STATUS_QUEUED,
                    EmailRecipientValidationService::STATUS_PROCESSING,
                    EmailRecipientValidationService::STATUS_SENT,
                    'Delivered',
                ])
                ->count(),
            'queued_count' => (int) ($counts->queued ?? 0),
        ]);

        $this->finalizeCampaignStatus($campaign->fresh());
    }

    private function finalizeCampaignStatus(EmailCampaign $campaign): void
    {
        $sent = (int) ($campaign->sent_count ?? $campaign->delivered_count ?? 0);
        $failed = (int) ($campaign->failed_count ?? 0);
        $hasLogs = $this->campaignHasMessageLogs($campaign);

        $finalStatus = match (true) {
            ! $hasLogs && (int) $campaign->total_emails > 0 => 'Failed',
            $sent > 0 && $failed === 0 => 'Completed',
            $sent > 0 => 'Partial',
            default => 'Failed',
        };

        $campaign->update([
            'status' => $finalStatus,
            'delivery_completed_at' => $campaign->delivery_completed_at ?? now(),
        ]);
    }

    private function campaignHasMessageLogs(EmailCampaign $campaign): bool
    {
        return EmailLog::query()->where('campaign_id', $campaign->id)->exists();
    }

    private function logComplianceSkips(EmailCampaign $campaign, array $skipSummary): void
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
            'EMAIL_CAMPAIGN',
            'Campaign Skip',
            (string) $campaign->id,
            $campaign->campaign_name.' · skipped '.implode(', ', $parts),
        );
    }
}
