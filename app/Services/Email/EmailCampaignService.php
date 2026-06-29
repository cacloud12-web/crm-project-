<?php

namespace App\Services\Email;

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
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        private readonly EmployeeDataScopeService $employeeDataScope,
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
                'emailLogs as delivered_emails_count' => fn ($query) => $query->where('email_status', 'Delivered'),
                'emailLogs as failed_emails_count' => fn ($query) => $query->where('email_status', 'Failed'),
                'emailLogs as queued_emails_count' => fn ($query) => $query->where('email_status', 'Queued'),
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
            'dispatch' => 'mapped_not_sent',
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

            $campaign = EmailCampaign::create([
                'campaign_name' => $data['campaign_name'],
                'campaign_type' => $data['campaign_type'],
                'audience_mode' => $data['audience_mode'],
                'audience_label' => $this->buildAudienceLabel($data),
                'audience_filters' => $this->extractAudienceFilters($data),
                'selected_ca_ids' => $data['audience_mode'] === 'selected_leads'
                    ? array_values(array_map('intval', $data['ca_ids'] ?? []))
                    : null,
                'subject' => $data['subject'],
                'body_template' => $data['body_template'],
                'scheduled_at' => $scheduledAt,
                'status' => $processNow ? 'Processing' : 'Scheduled',
                'performed_by' => $data['performed_by'] ?? 'System',
                'total_emails' => $recipients->count(),
                'queued_count' => 0,
                'skipped_count' => 0,
            ]);

            if ($this->campaignLogProcessor->shouldQueue($recipients->count())) {
                $this->campaignLogProcessor->dispatch('email', $campaign->id);

                return $campaign->fresh();
            }

            $now = now();
            $queuedCount = 0;
            $skippedCount = 0;
            $skipSummary = [];

            foreach ($recipients as $lead) {
                $eligibility = $this->eligibilityService->assess($lead, CommunicationEligibilityService::CHANNEL_EMAIL);
                $this->createMappedLog(
                    $campaign,
                    $lead,
                    $data['subject'],
                    $data['body_template'],
                    $eligibility,
                    $now,
                    $queuedCount,
                    $skippedCount,
                    $skipSummary,
                );
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

            if (EmailLog::query()->where('campaign_id', $campaignId)->exists()) {
                if ($campaign->status === 'Processing') {
                    $this->simulateDelivery($campaign);
                }

                return;
            }

            $data = array_merge([
                'audience_mode' => $campaign->audience_mode,
                'ca_ids' => $campaign->selected_ca_ids ?? [],
                'subject' => $campaign->subject,
                'body_template' => $campaign->body_template,
            ], $campaign->audience_filters ?? []);

            $recipients = $this->resolveAudience($data);
            $processNow = ! $campaign->scheduled_at || $campaign->scheduled_at->lte(now());
            $now = now();
            $queuedCount = 0;
            $skippedCount = 0;
            $skipSummary = [];

            foreach ($recipients as $lead) {
                $eligibility = $this->eligibilityService->assess($lead, CommunicationEligibilityService::CHANNEL_EMAIL);
                $this->createMappedLog(
                    $campaign,
                    $lead,
                    $data['subject'],
                    $data['body_template'],
                    $eligibility,
                    $now,
                    $queuedCount,
                    $skippedCount,
                    $skipSummary,
                );
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

            $campaign->update(['status' => 'Processing']);
            $this->simulateDelivery($campaign);
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

    public function dashboardMetrics(): array
    {
        $statusCounts = EmailLog::query()
            ->selectRaw("COUNT(*) FILTER (WHERE email_status = 'Delivered') as delivered")
            ->selectRaw("COUNT(*) FILTER (WHERE email_status = 'Failed') as failed")
            ->selectRaw("COUNT(*) FILTER (WHERE email_status = 'Queued') as queued")
            ->selectRaw('COUNT(*) as total')
            ->first();

        return [
            'email_campaigns_total' => EmailCampaign::query()->count(),
            'email_messages_total' => (int) ($statusCounts->total ?? 0),
            'email_delivered' => (int) ($statusCounts->delivered ?? 0),
            'email_failed' => (int) ($statusCounts->failed ?? 0),
            'email_queued' => (int) ($statusCounts->queued ?? 0),
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

    private function createMappedLog(
        EmailCampaign $campaign,
        CaMaster $lead,
        string $subjectTemplate,
        string $bodyTemplate,
        array $eligibility,
        $now,
        int &$queuedCount,
        int &$skippedCount,
        array &$skipSummary,
    ): void {
        $employeeId = $this->employeeDataScope->resolveEmployeeId(auth()->user());
        $subject = $this->goDaddyMailService->renderTemplate($subjectTemplate, $lead);
        $body = $this->goDaddyMailService->renderTemplate($bodyTemplate, $lead);

        if (! $eligibility['eligible']) {
            EmailLog::create([
                'campaign_id' => $campaign->id,
                'ca_id' => $lead->ca_id,
                'employee_id' => $employeeId,
                'recipient_email' => $lead->email_id,
                'subject' => $subject,
                'body' => $body,
                'email_status' => 'Skipped',
                'failed_reason' => $eligibility['skip_reason'],
                'error_message' => $eligibility['skip_reason'],
            ]);
            $skippedCount++;
            $skipSummary[$eligibility['skip_reason']] = ($skipSummary[$eligibility['skip_reason']] ?? 0) + 1;

            return;
        }

        $prepared = $this->goDaddyMailService->prepareForLead($lead, $subject, $body);

        if (! $prepared['valid']) {
            $error = implode('; ', $prepared['errors']);
            EmailLog::create([
                'campaign_id' => $campaign->id,
                'ca_id' => $lead->ca_id,
                'employee_id' => $employeeId,
                'recipient_email' => $lead->email_id,
                'subject' => $subject,
                'body' => $body,
                'email_status' => 'Failed',
                'failed_reason' => $error,
                'error_message' => $error,
            ]);
            $skippedCount++;

            return;
        }

        EmailLog::create([
            'campaign_id' => $campaign->id,
            'ca_id' => $lead->ca_id,
            'employee_id' => $employeeId,
            'recipient_email' => $lead->email_id,
            'subject' => $subject,
            'body' => $body,
            'email_status' => 'Queued',
            'queued_at' => $now,
            'provider_response' => $prepared['provider_response'],
        ]);
        $queuedCount++;
    }

    private function simulateDelivery(EmailCampaign $campaign): void
    {
        $logs = EmailLog::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->get();

        $delivered = 0;
        $failed = 0;
        $skipped = 0;
        $now = now();

        foreach ($logs as $index => $log) {
            if (in_array($log->email_status, ['Skipped', 'Failed'], true)) {
                $skipped++;

                continue;
            }

            $log->update([
                'email_status' => 'Processing',
                'sent_at' => $now->copy()->addMilliseconds($index * 5),
            ]);

            $shouldFail = ! $log->recipient_email || ! filter_var($log->recipient_email, FILTER_VALIDATE_EMAIL);

            if (! $shouldFail && random_int(1, 100) > 96) {
                $shouldFail = true;
            }

            if ($shouldFail) {
                $log->update([
                    'email_status' => 'Failed',
                    'failed_reason' => ! $log->recipient_email ? 'Missing email address' : 'Simulation: delivery failed',
                ]);
                $failed++;

                continue;
            }

            $log->update([
                'email_status' => 'Delivered',
                'delivered_at' => $now->copy()->addMilliseconds(($index * 5) + 10),
            ]);
            $delivered++;
        }

        $campaign->update([
            'status' => 'Completed',
            'delivered_count' => $delivered,
            'failed_count' => $failed,
            'queued_count' => 0,
            'skipped_count' => $skipped,
        ]);
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
