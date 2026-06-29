<?php

namespace App\Services\DemoConfirmation;

use App\Models\ActivityLog;
use App\Models\CaMaster;
use App\Models\DemoConfirmation;
use App\Models\DemoRescheduleLog;
use App\Models\FollowUp;
use App\Models\SmsLog;
use App\Services\Activity\ActivityLogService;
use App\Services\Cache\CrmCacheService;
use App\Services\Notifications\NotificationService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use App\Services\Sms\SmsAlertMappingService;
use App\Services\Sms\SmsSettingsService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DemoConfirmationService
{
    public const DEMO_FOLLOWUP_TYPE = 'Demo Scheduled';

    public const TEMPLATE_SCHEDULED = "Hello {{ca_name}},\n\nYour demo with CA Cloud Desk has been scheduled.\n\nDate: {{demo_date}}\nTime: {{demo_time}}\n\nReply:\nYES - to confirm\nNO - if this date or time is incorrect.\n\nThank you.";

    public const TEMPLATE_RESCHEDULED = "Hello {{ca_name}},\n\nYour demo has been rescheduled.\n\nNew Date: {{demo_date}}\nNew Time: {{demo_time}}\n\nReply YES to confirm.\nReply NO if incorrect.\n\nThank you.";

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        private readonly SmsAlertMappingService $smsAlertMappingService,
        private readonly SmsSettingsService $smsSettingsService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly CrmCacheService $cacheService,
    ) {}

    public function isDemoFollowUp(FollowUp $followUp): bool
    {
        return $followUp->followup_type === self::DEMO_FOLLOWUP_TYPE;
    }

    public function handleFollowUpCreated(FollowUp $followUp): ?DemoConfirmation
    {
        if (! $this->isDemoFollowUp($followUp) || ! $followUp->scheduled_date) {
            return null;
        }

        return $this->createConfirmationForFollowUp($followUp, false);
    }

    public function handleFollowUpUpdated(
        FollowUp $followUp,
        ?Carbon $previousScheduledDate,
        ?string $previousFollowupType = null,
    ): ?DemoConfirmation {
        if (! $this->isDemoFollowUp($followUp) || ! $followUp->scheduled_date) {
            return null;
        }

        $becameDemo = $previousFollowupType !== self::DEMO_FOLLOWUP_TYPE;
        $dateChanged = $previousScheduledDate
            && ! $followUp->scheduled_date->equalTo($previousScheduledDate);

        if ($becameDemo) {
            return $this->createConfirmationForFollowUp($followUp, false);
        }

        if ($dateChanged) {
            return $this->rescheduleConfirmation($followUp, $previousScheduledDate);
        }

        return null;
    }

    public function createConfirmationForFollowUp(FollowUp $followUp, bool $isReschedule): DemoConfirmation
    {
        $confirmation = $this->createConfirmationRecord($followUp, $isReschedule);
        $followUp->loadMissing('caMaster');
        $lead = $followUp->caMaster ?? CaMaster::query()->findOrFail($followUp->ca_id);
        $firm = $lead->firm_name ?: $lead->ca_name ?: 'Lead #'.$lead->ca_id;

        if (! $isReschedule) {
            $this->logActivity(
                'Demo Scheduled',
                (string) $confirmation->id,
                $firm.' · '.$this->formatDemoSlotFromConfirmation($confirmation).' · Lead #'.$lead->ca_id,
                afterValue: $this->snapshot($confirmation),
            );
        }

        $this->queueConfirmationSms($confirmation, $lead, $isReschedule);
        $this->invalidateDashboardCache();

        return $confirmation->fresh(['smsLog', 'employee']);
    }

    private function createConfirmationRecord(FollowUp $followUp, bool $isReschedule): DemoConfirmation
    {
        $followUp->loadMissing(['caMaster', 'employee']);
        $scheduled = $followUp->scheduled_date;
        $previous = $this->latestActiveForFollowUp((int) $followUp->followup_id);

        if ($previous && $previous->isPending()) {
            $previous->update(['confirmation_status' => DemoConfirmation::STATUS_SUPERSEDED]);
        }

        return DemoConfirmation::query()->create([
            'lead_id' => $followUp->ca_id,
            'followup_id' => $followUp->followup_id,
            'employee_id' => $followUp->employee_id,
            'demo_date' => $scheduled->toDateString(),
            'demo_time' => $scheduled->format('H:i:s'),
            'confirmation_status' => DemoConfirmation::STATUS_PENDING,
            'is_reschedule' => $isReschedule,
            'previous_confirmation_id' => $previous?->id,
        ]);
    }

    public function rescheduleConfirmation(FollowUp $followUp, Carbon $previousScheduledDate): DemoConfirmation
    {
        $followUp->loadMissing(['caMaster', 'employee']);
        $previous = $this->latestForFollowUp((int) $followUp->followup_id);
        $scheduled = $followUp->scheduled_date;
        $lead = $followUp->caMaster ?? CaMaster::query()->findOrFail($followUp->ca_id);
        $firm = $lead->firm_name ?: $lead->ca_name ?: 'Lead #'.$lead->ca_id;

        $confirmation = $this->createConfirmationForFollowUp($followUp, true);

        DemoRescheduleLog::query()->create([
            'demo_confirmation_id' => $confirmation->id,
            'followup_id' => $followUp->followup_id,
            'lead_id' => $followUp->ca_id,
            'old_demo_date' => $previousScheduledDate->toDateString(),
            'old_demo_time' => $previousScheduledDate->format('H:i:s'),
            'new_demo_date' => $scheduled->toDateString(),
            'new_demo_time' => $scheduled->format('H:i:s'),
            'changed_by' => auth()->user()?->name ?? 'System',
            'changed_by_employee_id' => $this->employeeDataScope->resolveEmployeeId(auth()->user()),
            'reason' => $followUp->remarks,
        ]);

        $this->logActivity(
            'Demo Rescheduled',
            (string) $confirmation->id,
            $firm.' · '.$this->formatDemoSlot($previousScheduledDate).' → '.$this->formatDemoSlot($scheduled),
            beforeValue: $this->formatDemoSlot($previousScheduledDate),
            afterValue: $this->formatDemoSlot($scheduled),
        );

        $this->notifyManagement(
            'demo_rescheduled',
            'Demo rescheduled',
            $firm.' — '.$this->formatDemoSlot($previousScheduledDate).' → '.$this->formatDemoSlot($scheduled),
            [
                'entity_type' => 'demo_confirmation',
                'entity_id' => (string) $confirmation->id,
                'dedup_key' => 'demo_rescheduled:'.$confirmation->id,
                'payload' => [
                    'lead_id' => $lead->ca_id,
                    'followup_id' => $followUp->followup_id,
                    'old_demo_date' => $previousScheduledDate->toDateString(),
                    'old_demo_time' => $previousScheduledDate->format('H:i'),
                    'new_demo_date' => $scheduled->toDateString(),
                    'new_demo_time' => $scheduled->format('H:i'),
                ],
            ],
        );

        return $confirmation;
    }

    /**
     * Process inbound SMS reply. Only callable by system/webhook — not employees.
     */
    public function processInboundReply(DemoConfirmation $confirmation, string $reply): DemoConfirmation
    {
        $normalized = $this->normalizeReply($reply);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Reply must be YES or NO.');
        }

        if (! $confirmation->isPending()) {
            throw new \InvalidArgumentException('This demo confirmation is no longer pending.');
        }

        return DB::transaction(function () use ($confirmation, $normalized, $reply) {
            $confirmation->refresh();
            $confirmation->loadMissing(['lead', 'employee']);
            $lead = $confirmation->lead;
            $firm = $lead?->firm_name ?: $lead?->ca_name ?: 'Lead #'.$confirmation->lead_id;

            if ($normalized === 'YES') {
                $confirmation->update([
                    'confirmation_status' => DemoConfirmation::STATUS_CONFIRMED,
                    'customer_reply' => trim($reply),
                    'confirmation_source' => DemoConfirmation::SOURCE_SMS,
                    'confirmed_at' => now(),
                ]);

                $this->logActivity(
                    'Customer Confirmed',
                    (string) $confirmation->id,
                    $firm.' · '.$this->formatDemoSlotFromConfirmation($confirmation),
                    afterValue: DemoConfirmation::STATUS_CONFIRMED,
                );

                $this->notifyManagement(
                    'demo_confirmed',
                    'Customer confirmed demo',
                    $firm.' confirmed '.$this->formatDemoSlotFromConfirmation($confirmation),
                    $this->notificationExtra($confirmation),
                );
            } else {
                $confirmation->update([
                    'confirmation_status' => DemoConfirmation::STATUS_REJECTED,
                    'customer_reply' => trim($reply),
                    'confirmation_source' => DemoConfirmation::SOURCE_SMS,
                    'confirmed_at' => now(),
                ]);

                $this->logActivity(
                    'Customer Rejected',
                    (string) $confirmation->id,
                    $firm.' · '.$this->formatDemoSlotFromConfirmation($confirmation),
                    afterValue: DemoConfirmation::STATUS_REJECTED,
                );

                $type = $confirmation->is_reschedule ? 'demo_rejected_after_reschedule' : 'demo_rejected';
                $title = $confirmation->is_reschedule ? 'Customer rejected rescheduled demo' : 'Customer rejected demo';

                $this->notifyManagement(
                    $type,
                    $title,
                    $firm.' rejected '.$this->formatDemoSlotFromConfirmation($confirmation),
                    $this->notificationExtra($confirmation),
                );
            }

            $this->invalidateDashboardCache();

            return $confirmation->fresh(['smsLog', 'employee', 'lead']);
        });
    }

    public function queueConfirmationSms(
        DemoConfirmation $confirmation,
        ?CaMaster $lead = null,
        bool $isReschedule = false,
    ): ?SmsLog {
        $lead = CaMaster::query()->find($confirmation->lead_id);
        if (! $lead) {
            return null;
        }

        $message = $this->renderMessage($lead, $confirmation, $isReschedule);
        $settings = $this->smsSettingsService->current();
        $prepared = $this->smsAlertMappingService->prepareForLead($lead, $message, $settings);

        if (! $prepared['valid']) {
            $this->logActivity(
                'Confirmation SMS Skipped',
                (string) $confirmation->id,
                ($lead->firm_name ?: $lead->ca_name).' — '.implode(' ', $prepared['errors']),
            );

            return null;
        }

        $employeeId = $confirmation->employee_id
            ?? $this->employeeDataScope->resolveEmployeeId(auth()->user());

        $smsLog = SmsLog::query()->create([
            'campaign_id' => null,
            'ca_id' => $lead->ca_id,
            'employee_id' => $employeeId,
            'mobile_no' => $prepared['payload']['mobileno'],
            'sender_id' => $settings->sender_id,
            'message' => $message,
            'sms_status' => 'Mapped',
            'provider_response' => $prepared['provider_response'],
            'sent_at' => null,
        ]);

        $confirmation->update([
            'sms_log_id' => $smsLog->id,
            'last_sms_sent_at' => now(),
        ]);

        $firm = $lead->firm_name ?: $lead->ca_name ?: 'Lead #'.$lead->ca_id;
        $this->logActivity(
            'Confirmation SMS Sent',
            (string) $confirmation->id,
            $firm.' · '.$this->formatDemoSlotFromConfirmation($confirmation),
            afterValue: 'mapped_not_sent',
        );

        return $smsLog;
    }

    public function renderMessage(CaMaster $lead, DemoConfirmation $confirmation, bool $isReschedule): string
    {
        $template = $isReschedule ? self::TEMPLATE_RESCHEDULED : self::TEMPLATE_SCHEDULED;
        $demoDate = Carbon::parse($confirmation->demo_date)->format('l, j M Y');
        $demoTime = Carbon::parse($confirmation->demo_time)->format('g:i A');

        $replacements = [
            '{{ca_name}}' => $lead->ca_name ?? '',
            '{{firm_name}}' => $lead->firm_name ?? '',
            '{{demo_date}}' => $demoDate,
            '{{demo_time}}' => $demoTime,
            '{{mobile}}' => $lead->mobile_no ?? '',
        ];

        return strtr($template, $replacements);
    }

    public function latestForLead(int $leadId): ?DemoConfirmation
    {
        return DemoConfirmation::query()
            ->where('lead_id', $leadId)
            ->orderByDesc('id')
            ->with(['smsLog', 'employee'])
            ->first();
    }

    public function latestForFollowUp(int $followupId): ?DemoConfirmation
    {
        return DemoConfirmation::query()
            ->where('followup_id', $followupId)
            ->orderByDesc('id')
            ->with(['smsLog', 'employee'])
            ->first();
    }

    public function latestActiveForFollowUp(int $followupId): ?DemoConfirmation
    {
        return DemoConfirmation::query()
            ->where('followup_id', $followupId)
            ->whereIn('confirmation_status', [
                DemoConfirmation::STATUS_PENDING,
                DemoConfirmation::STATUS_CONFIRMED,
                DemoConfirmation::STATUS_REJECTED,
            ])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int, DemoConfirmation>
     */
    public function historyForLead(int $leadId, int $limit = 20): Collection
    {
        return DemoConfirmation::query()
            ->where('lead_id', $leadId)
            ->orderByDesc('id')
            ->limit($limit)
            ->with(['smsLog', 'employee', 'rescheduleLogs'])
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForLead(int $leadId): array
    {
        $latest = $this->latestForLead($leadId);

        if (! $latest) {
            return [
                'has_confirmation' => false,
                'status' => null,
                'status_label' => '—',
                'demo_date' => null,
                'demo_time' => null,
                'last_sms_sent_at' => null,
                'confirmed_at' => null,
                'confirmed_by' => null,
                'confirmation_source' => null,
                'is_reschedule' => false,
            ];
        }

        return [
            'has_confirmation' => true,
            'id' => $latest->id,
            'status' => $latest->confirmation_status,
            'status_label' => $this->statusLabel($latest->confirmation_status),
            'demo_date' => $latest->demo_date?->toDateString(),
            'demo_time' => Carbon::parse($latest->demo_time)->format('H:i'),
            'demo_slot' => $this->formatDemoSlotFromConfirmation($latest),
            'last_sms_sent_at' => $latest->last_sms_sent_at?->toIso8601String(),
            'confirmed_at' => $latest->confirmed_at?->toIso8601String(),
            'confirmed_by' => $latest->confirmation_source === DemoConfirmation::SOURCE_SMS
                ? 'Customer (SMS)'
                : ($latest->employee?->name ?? null),
            'confirmation_source' => $latest->confirmation_source,
            'is_reschedule' => $latest->is_reschedule,
            'customer_reply' => $latest->customer_reply,
        ];
    }

    public function timelineForLead(int $leadId): Collection
    {
        $confirmationIds = DemoConfirmation::query()
            ->where('lead_id', $leadId)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($confirmationIds === []) {
            return collect();
        }

        return ActivityLog::query()
            ->where('module_name', 'DEMO_CONFIRMATION')
            ->whereIn('record_id', $confirmationIds)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'action' => $log->action,
                'description' => $log->description,
                'performed_by' => $log->performed_by,
                'timestamp' => $log->created_at?->toIso8601String(),
            ]);
    }

    /**
     * @return array<string, int>
     */
    public function dashboardMetrics(?int $employeeId = null): array
    {
        $latestIds = DemoConfirmation::query()
            ->selectRaw('MAX(id) as id')
            ->when($employeeId, fn (Builder $q) => $q->where('employee_id', $employeeId))
            ->groupBy('followup_id')
            ->pluck('id');

        $query = DemoConfirmation::query()->whereIn('id', $latestIds);

        $pending = (clone $query)->where('confirmation_status', DemoConfirmation::STATUS_PENDING)->count();
        $confirmed = (clone $query)->where('confirmation_status', DemoConfirmation::STATUS_CONFIRMED)->count();
        $rejected = (clone $query)->where('confirmation_status', DemoConfirmation::STATUS_REJECTED)->count();

        $rescheduleQuery = DemoRescheduleLog::query()
            ->when($employeeId, function (Builder $q) use ($employeeId) {
                $q->whereHas('demoConfirmation', fn (Builder $inner) => $inner->where('employee_id', $employeeId));
            });

        $rescheduled = (clone $rescheduleQuery)->count();
        $rejectedAfterReschedule = DemoConfirmation::query()
            ->where('is_reschedule', true)
            ->where('confirmation_status', DemoConfirmation::STATUS_REJECTED)
            ->when($employeeId, fn (Builder $q) => $q->where('employee_id', $employeeId))
            ->count();

        return [
            'demo_confirmation_pending' => $pending,
            'demo_confirmation_confirmed' => $confirmed,
            'demo_confirmation_rejected' => $rejected,
            'demo_confirmation_rescheduled' => $rescheduled,
            'demo_confirmation_rejected_after_reschedule' => $rejectedAfterReschedule,
        ];
    }

    public function normalizeReply(string $reply): ?string
    {
        $value = strtoupper(trim($reply));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        if (in_array($value, ['YES', 'Y', 'CONFIRM', 'CONFIRMED', 'OK'], true)) {
            return 'YES';
        }

        if (in_array($value, ['NO', 'N', 'REJECT', 'REJECTED', 'INCORRECT', 'WRONG'], true)) {
            return 'NO';
        }

        return null;
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            DemoConfirmation::STATUS_PENDING => 'Pending',
            DemoConfirmation::STATUS_CONFIRMED => 'Confirmed',
            DemoConfirmation::STATUS_REJECTED => 'Rejected',
            DemoConfirmation::STATUS_SUPERSEDED => 'Superseded',
            default => ucfirst($status),
        };
    }

    public function ensureCanAccessLead(int $leadId): void
    {
        $this->employeeDataScope->ensureCanAccessCaMaster($leadId);
    }

    public function ensureCanSimulateReply(): void
    {
        $role = app(RbacService::class)->roleKey(auth()->user());
        if (! in_array($role, ['admin', 'super_admin'], true)) {
            throw new AuthorizationException('Only administrators can simulate customer SMS replies during the integration phase.');
        }
    }

    private function logActivity(
        string $action,
        string $recordId,
        string $description,
        mixed $beforeValue = null,
        mixed $afterValue = null,
    ): void {
        $this->activityLogService->log(
            'DEMO_CONFIRMATION',
            $action,
            $recordId,
            $description,
            beforeValue: $beforeValue,
            afterValue: $afterValue,
        );
    }

    private function notifyManagement(string $type, string $title, string $message, array $extra = []): void
    {
        $this->notificationService->notifyManagement($type, $title, $message, $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationExtra(DemoConfirmation $confirmation): array
    {
        return [
            'entity_type' => 'demo_confirmation',
            'entity_id' => (string) $confirmation->id,
            'dedup_key' => 'demo_confirmation:'.$confirmation->id.':'.$confirmation->confirmation_status,
            'payload' => [
                'lead_id' => $confirmation->lead_id,
                'followup_id' => $confirmation->followup_id,
                'status' => $confirmation->confirmation_status,
                'is_reschedule' => $confirmation->is_reschedule,
            ],
        ];
    }

    private function formatDemoSlot(Carbon $scheduled): string
    {
        return $scheduled->format('D, j M Y').' '.$scheduled->format('g:i A');
    }

    public function formatDemoSlotFromConfirmation(DemoConfirmation $confirmation): string
    {
        $date = Carbon::parse($confirmation->demo_date)->format('D, j M Y');
        $time = Carbon::parse($confirmation->demo_time)->format('g:i A');

        return $date.' '.$time;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(DemoConfirmation $confirmation): array
    {
        return [
            'status' => $confirmation->confirmation_status,
            'demo_date' => $confirmation->demo_date?->toDateString(),
            'demo_time' => $confirmation->demo_time,
            'is_reschedule' => $confirmation->is_reschedule,
        ];
    }

    private function logBelongsToLead(array $log, int $leadId): bool
    {
        $confirmationId = (int) ($log['record_id'] ?? 0);
        if (! $confirmationId) {
            return false;
        }

        return DemoConfirmation::query()
            ->where('id', $confirmationId)
            ->where('lead_id', $leadId)
            ->exists();
    }

    private function invalidateDashboardCache(): void
    {
        $this->cacheService->forgetDashboardMetrics('org');
        $scopeKey = $this->employeeDataScope->cacheScopeKey();
        if ($scopeKey !== 'org') {
            $this->cacheService->forgetDashboardMetrics($scopeKey);
        }
    }
}
