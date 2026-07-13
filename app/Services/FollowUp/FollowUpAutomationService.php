<?php

namespace App\Services\FollowUp;

use App\Models\FollowUp;
use App\Models\FollowUpRescheduleLog;
use App\Models\Task;
use App\Services\Activity\ActivityLogService;
use App\Services\DemoConfirmation\DemoConfirmationService;
use App\Services\Leads\CaMasterStatusSyncService;
use App\Services\Leads\LeadQualityHistoryService;
use App\Services\Notifications\NotificationService;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class FollowUpAutomationService
{
    public function __construct(
        private readonly FollowUpSequenceService $sequenceService,
        private readonly FollowUpHistoryService $historyService,
        private readonly TaskService $taskService,
        private readonly FollowUpReminderService $reminderService,
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        private readonly DemoConfirmationService $demoConfirmationService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly LeadQualityHistoryService $leadQualityHistory,
        private readonly CaMasterStatusSyncService $statusSyncService,
    ) {}

    public function recordCallOutcome(array $data): array
    {
        $outcome = (string) ($data['outcome'] ?? '');
        $outcomeConfig = config('followup_automation.outcomes.'.$outcome);
        if (! $outcomeConfig) {
            throw new InvalidArgumentException('Invalid call outcome: '.$outcome);
        }

        $current = null;
        if (! empty($data['followup_id'])) {
            $this->employeeDataScope->ensureCanAccessFollowUp($data['followup_id']);
            $current = FollowUp::query()->findOrFail($data['followup_id']);
        }

        $user = auth()->user();
        $caId = (int) ($data['ca_id'] ?? $current?->ca_id);
        if ($caId <= 0) {
            throw new InvalidArgumentException('Lead is required.');
        }

        $employeeId = (int) ($data['employee_id']
            ?? $current?->employee_id
            ?? $this->employeeDataScope->scopedEmployeeId($user)
            ?? $this->employeeDataScope->resolveEmployeeId($user)
            ?? 0);
        if ($employeeId <= 0) {
            $employeeId = (int) (\App\Models\LeadAssignmentEngine::query()
                ->where('ca_id', $caId)
                ->where('status', 'Active')
                ->value('employee_id') ?? 0);
        }
        if ($employeeId <= 0) {
            $employeeId = null;
        }

        $remarks = $data['remarks'] ?? null;
        $completedFollowUp = null;
        $nextFollowUp = null;

        if ($current) {
            $completedFollowUp = $this->completeFollowUp($current, $outcome, $remarks);
        } else {
            $this->historyService->record(
                $caId,
                'Call Created',
                null,
                $employeeId,
                $outcome,
                $remarks,
                ['source' => 'call_outcome'],
            );
            $this->activityLogService->log(
                'FOLLOW_UP_MANAGEMENT',
                'Call Created',
                (string) $caId,
                $outcome.($remarks ? ' — '.$remarks : ''),
            );
        }

        if (($outcomeConfig['requires_followup'] ?? false) === true
            || (($outcomeConfig['advance_sequence'] ?? false) && $this->sequenceService->shouldAdvanceSequence($outcome))) {
            $nextFollowUp = $this->scheduleNextFollowUp(
                $caId,
                $employeeId,
                $outcome,
                $outcomeConfig,
                $completedFollowUp,
                $data,
            );
        }

        if (($outcomeConfig['closes_sequence'] ?? false) && $completedFollowUp) {
            FollowUp::query()
                ->where('ca_id', $caId)
                ->whereIn('status', config('followup_automation.open_statuses', []))
                ->where('followup_id', '!=', $completedFollowUp->followup_id)
                ->update(['status' => 'Closed']);
        }

        if (($outcomeConfig['marks_wrong_number'] ?? false) === true) {
            $lead = \App\Models\CaMaster::query()->find($caId);
            if ($lead) {
                $this->leadQualityHistory->markWrongNumber($lead, 'Call outcome: '.$outcome, $user);
            }
        }

        return [
            'completed_follow_up' => $completedFollowUp,
            'next_follow_up' => $nextFollowUp,
            'outcome' => $outcome,
        ];
    }

    public function completeFollowUp(FollowUp $followUp, ?string $outcome = null, ?string $remarks = null): FollowUp
    {
        $followUp->update([
            'outcome' => $outcome ?? $followUp->outcome,
            'remarks' => $remarks ?? $followUp->remarks,
            'status' => 'Completed',
        ]);

        $followUp = $followUp->fresh(['caMaster', 'employee']);

        Task::query()
            ->where('followup_id', $followUp->followup_id)
            ->whereIn('status', ['Pending', 'Overdue'])
            ->update(['status' => 'Completed', 'completed_at' => now()]);

        $this->reminderService->cancelPendingForFollowUp($followUp->followup_id);

        $firm = $followUp->caMaster?->firm_name ?? 'Lead #'.$followUp->ca_id;
        $this->activityLogService->log(
            'FOLLOW_UP_MANAGEMENT',
            'Follow-up Completed',
            (string) $followUp->followup_id,
            ($outcome ?? $followUp->followup_type).' · '.$firm,
        );

        $this->historyService->record(
            $followUp->ca_id,
            'Follow-up Completed',
            $followUp->followup_id,
            $followUp->employee_id,
            $outcome,
            $remarks,
            ['followup_type' => $followUp->followup_type],
        );

        $crmStatus = $this->statusSyncService->statusForCallOutcome((string) ($outcome ?? ''))
            ?? $this->statusSyncService->statusForFollowUp($followUp);
        if ($crmStatus) {
            $lead = $followUp->caMaster ?? \App\Models\CaMaster::query()->find($followUp->ca_id);
            if ($lead) {
                $this->statusSyncService->apply(
                    $lead,
                    $crmStatus,
                    $this->statusSyncService->workflowExtrasForStatus($crmStatus),
                );
            }
        }

        return $followUp;
    }

    public function afterFollowUpCreated(FollowUp $followUp): void
    {
        $task = $this->taskService->createForFollowUp($followUp);
        $this->reminderService->scheduleForFollowUp($followUp, $task);

        $this->historyService->record(
            $followUp->ca_id,
            'Follow-up Created',
            $followUp->followup_id,
            $followUp->employee_id,
            $followUp->outcome,
            $followUp->remarks,
            [
                'followup_type' => $followUp->followup_type,
                'scheduled_date' => $followUp->scheduled_date?->toIso8601String(),
                'priority' => $followUp->priority,
                'source' => $followUp->source,
                'sequence_step' => $followUp->sequence_step,
            ],
        );

        $this->notifyNewFollowUp($followUp);
    }

    public function handleReschedule(
        FollowUp $followUp,
        Carbon $previousScheduled,
        Carbon $newScheduled,
        ?string $reason = null,
    ): void {
        if ($previousScheduled->equalTo($newScheduled)) {
            return;
        }

        $performedBy = auth()->user()?->name ?? 'System';

        FollowUpRescheduleLog::query()->create([
            'followup_id' => $followUp->followup_id,
            'ca_id' => $followUp->ca_id,
            'old_scheduled_at' => $previousScheduled,
            'new_scheduled_at' => $newScheduled,
            'reason' => $reason,
            'changed_by' => $performedBy,
            'changed_at' => now(),
        ]);

        $followUp->update([
            'is_rescheduled' => true,
            'rescheduled_at' => now(),
            'rescheduled_by' => $performedBy,
            'reschedule_reason' => $reason,
        ]);

        $fresh = $followUp->fresh();
        $this->reminderService->cancelPendingForFollowUp($followUp->followup_id);
        $this->taskService->syncFromFollowUp($fresh);
        $this->reminderService->scheduleForFollowUp($fresh);

        $firm = $followUp->caMaster?->firm_name ?? 'Lead #'.$followUp->ca_id;
        $description = sprintf(
            '%s rescheduled from %s to %s',
            $firm,
            $previousScheduled->format('d M Y H:i'),
            $newScheduled->format('d M Y H:i'),
        );

        $this->activityLogService->log(
            'FOLLOW_UP_MANAGEMENT',
            'Follow-up Rescheduled',
            (string) $followUp->followup_id,
            $description,
            beforeValue: $previousScheduled->toIso8601String(),
            afterValue: $newScheduled->toIso8601String(),
        );

        $this->historyService->record(
            $followUp->ca_id,
            str_contains(strtolower($followUp->followup_type), 'demo') ? 'Demo Rescheduled' : 'Follow-up Rescheduled',
            $followUp->followup_id,
            $followUp->employee_id,
            null,
            $reason,
            [
                'old_scheduled_at' => $previousScheduled->toIso8601String(),
                'new_scheduled_at' => $newScheduled->toIso8601String(),
            ],
        );

        $this->notificationService->notifyManagement(
            str_contains(strtolower($followUp->followup_type), 'demo') ? 'demo_rescheduled' : 'followup_rescheduled',
            'Follow-up rescheduled',
            $description.($reason ? ' — '.$reason : ''),
            [
                'entity_type' => 'follow_up',
                'entity_id' => (string) $followUp->followup_id,
                'dedup_key' => 'followup_reschedule:'.$followUp->followup_id.':'.now()->timestamp,
                'payload' => [
                    'ca_id' => $followUp->ca_id,
                    'old' => $previousScheduled->toIso8601String(),
                    'new' => $newScheduled->toIso8601String(),
                ],
            ],
        );
    }

    public function markOverdueFollowUps(): int
    {
        $openStatuses = array_values(array_filter(
            config('followup_automation.open_statuses', []),
            fn (string $status) => $status !== 'Overdue',
        ));
        $count = 0;

        FollowUp::query()
            ->with('caMaster:ca_id,firm_name')
            ->whereIn('status', $openStatuses)
            ->whereDate('scheduled_date', '<', now()->toDateString())
            ->chunkById(100, function ($followUps) use (&$count) {
                foreach ($followUps as $followUp) {
                    $followUp->update(['status' => 'Overdue']);
                    Task::query()
                        ->where('followup_id', $followUp->followup_id)
                        ->whereIn('status', ['Pending'])
                        ->update(['status' => 'Overdue']);

                    $firm = $followUp->caMaster?->firm_name ?? 'Lead #'.$followUp->ca_id;
                    $this->activityLogService->log(
                        'FOLLOW_UP_MANAGEMENT',
                        'Overdue Follow-up',
                        (string) $followUp->followup_id,
                        $firm.' — '.$followUp->followup_type,
                    );
                    $this->historyService->record(
                        $followUp->ca_id,
                        'Overdue Follow-up',
                        $followUp->followup_id,
                        $followUp->employee_id,
                        null,
                        'Marked overdue',
                    );
                    $this->notificationService->notifyManagement(
                        'followup_overdue',
                        'Overdue follow-up',
                        $firm.' — '.$followUp->followup_type,
                        [
                            'entity_type' => 'follow_up',
                            'entity_id' => (string) $followUp->followup_id,
                            'dedup_key' => 'followup_overdue:'.$followUp->followup_id.':'.now()->toDateString(),
                        ],
                    );
                    $count++;
                }
            }, 'followup_id');

        return $count;
    }

    private function scheduleNextFollowUp(
        int $caId,
        int $employeeId,
        string $outcome,
        array $outcomeConfig,
        ?FollowUp $parent,
        array $data,
    ): FollowUp {
        $scheduledAt = $this->resolveNextSchedule($outcome, $outcomeConfig, $parent, $data);
        $sequenceStep = $parent?->sequence_step;
        $nextStep = null;

        if ($this->sequenceService->shouldAdvanceSequence($outcome)) {
            $nextStep = $this->sequenceService->nextSequenceStep($sequenceStep);
            if ($nextStep !== null) {
                $scheduledAt = $this->sequenceService->scheduleDateForStep($nextStep, $parent?->scheduled_date);
                $sequenceStep = $nextStep;
            }
        }

        $followUp = FollowUp::query()->create([
            'ca_id' => $caId,
            'employee_id' => $employeeId,
            'created_by_user_id' => auth()->id(),
            'parent_followup_id' => $parent?->followup_id,
            'followup_type' => $outcomeConfig['followup_type'] ?? 'Call',
            'remarks' => $data['remarks'] ?? null,
            'scheduled_date' => $scheduledAt,
            'status' => 'Pending',
            'priority' => $outcomeConfig['priority'] ?? 'Normal',
            'sequence_step' => $sequenceStep,
            'is_auto_generated' => true,
            'source' => $nextStep ? 'auto_sequence' : 'call_outcome',
        ]);

        $followUp->load('caMaster:ca_id,firm_name');
        $firm = $followUp->caMaster?->firm_name ?? 'Lead #'.$followUp->ca_id;

        $this->activityLogService->log(
            'FOLLOW_UP_MANAGEMENT',
            'Follow-up Create',
            (string) $followUp->followup_id,
            $followUp->followup_type.' · '.$firm,
        );

        $label = $nextStep ? 'Day '.$nextStep.' Follow-up Created' : 'Follow-up Created';
        $this->historyService->record(
            $caId,
            $label,
            $followUp->followup_id,
            $employeeId,
            $outcome,
            $data['remarks'] ?? null,
            ['sequence_step' => $sequenceStep, 'scheduled_at' => $scheduledAt->toIso8601String()],
        );

        $this->afterFollowUpCreated($followUp);

        if ($followUp->followup_type === 'Demo Scheduled') {
            $this->demoConfirmationService->handleFollowUpCreated($followUp);
        }

        return $followUp->fresh(['caMaster', 'employee']);
    }

    public function createSequenceFollowUp(string $outcome, FollowUp $completedFollowUp, ?string $remarks = null): ?FollowUp
    {
        $outcomeConfig = config('followup_automation.outcomes.'.$outcome);
        if (! is_array($outcomeConfig) || ! ($outcomeConfig['advance_sequence'] ?? false)) {
            return null;
        }

        if (! $this->sequenceService->shouldAdvanceSequence($outcome)) {
            return null;
        }

        return $this->scheduleNextFollowUp(
            (int) $completedFollowUp->ca_id,
            (int) ($completedFollowUp->employee_id ?? 0),
            $outcome,
            $outcomeConfig,
            $completedFollowUp,
            ['remarks' => $remarks],
        );
    }

    private function resolveNextSchedule(
        string $outcome,
        array $outcomeConfig,
        ?FollowUp $parent,
        array $data,
    ): Carbon {
        if (! empty($data['next_followup_date'])) {
            $date = Carbon::parse($data['next_followup_date']);
            if (! empty($data['next_followup_time'])) {
                [$h, $m] = array_pad(explode(':', (string) $data['next_followup_time']), 2, '0');

                return $date->setTime((int) $h, (int) $m);
            }

            return $date->setTime(10, 0);
        }

        if (($outcomeConfig['manual_schedule'] ?? false) && $parent?->scheduled_date) {
            return $parent->scheduled_date->copy()->addDay()->setTime(10, 0);
        }

        if ($this->sequenceService->shouldAdvanceSequence($outcome)) {
            $step = $this->sequenceService->nextSequenceStep($parent?->sequence_step);

            return $step
                ? $this->sequenceService->scheduleDateForStep($step, $parent?->scheduled_date ?? now())
                : now()->addDay()->setTime(10, 0);
        }

        return now()->addDay()->setTime(10, 0);
    }

    private function notifyNewFollowUp(FollowUp $followUp): void
    {
        $followUp->loadMissing('caMaster:ca_id,firm_name', 'employee:employee_id,email_id');
        $firm = $followUp->caMaster?->firm_name ?? 'Lead #'.$followUp->ca_id;
        $title = 'New follow-up assigned';
        $message = $firm.' — '.$followUp->followup_type.' on '.$followUp->scheduled_date?->format('d M Y H:i');

        $userId = $this->notificationService->resolveUserIdByEmployeeEmail($followUp->employee?->email_id);
        if ($userId) {
            $this->notificationService->notifyUser($userId, 'followup_assigned', $title, $message, [
                'entity_type' => 'follow_up',
                'entity_id' => (string) $followUp->followup_id,
                'dedup_key' => 'followup_assigned:'.$followUp->followup_id,
            ]);
        }
    }
}
