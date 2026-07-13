<?php

namespace App\Services\Workflow;

use App\Models\CallLog;
use App\Models\CaMaster;
use App\Models\DemoResult;
use App\Models\DemoSchedule;
use App\Models\AssignmentHistory;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\PurchasedCustomer;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Assignment\AssignmentRecorder;
use App\Services\Cache\CrmCacheService;
use App\Services\Sales\SalesListService;
use App\Services\FollowUp\FollowUpAutomationService;
use App\Services\FollowUp\FollowUpHistoryService;
use App\Services\Leads\CaMasterStatusSyncService;
use App\Services\Leads\LeadQualityHistoryService;
use App\Services\Demo\DemoAvailabilityService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class LeadWorkflowService
{
    public function __construct(
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly FollowUpHistoryService $historyService,
        private readonly FollowUpAutomationService $automationService,
        private readonly ActivityLogService $activityLogService,
        private readonly LeadQualityHistoryService $leadQualityHistory,
        private readonly DemoReminderService $demoReminderService,
        private readonly CrmCacheService $cacheService,
        private readonly RbacService $rbacService,
        private readonly CaMasterStatusSyncService $statusSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordCall(array $data, ?User $actor = null): array
    {
        $actor ??= auth()->user();
        $caId = (int) ($data['ca_id'] ?? 0);
        $followUpId = ! empty($data['followup_id']) ? (int) $data['followup_id'] : null;
        $current = $followUpId
            ? FollowUp::query()->find($followUpId)
            : null;

        if ($caId <= 0 && $current) {
            $caId = (int) $current->ca_id;
        }
        if ($caId <= 0) {
            throw new InvalidArgumentException('Lead is required.');
        }

        $this->employeeDataScope->ensureCanAccessCaMaster($caId);

        $employeeId = $this->resolveEmployeeId($data, $actor, $caId, $current);
        $status = (string) ($data['call_status'] ?? $data['outcome'] ?? '');
        if ($status === '') {
            throw new InvalidArgumentException('Please select a call status.');
        }

        $note = trim((string) ($data['call_note'] ?? $data['remarks'] ?? ''));
        if ($note === '') {
            throw new InvalidArgumentException('Call note is required.');
        }

        $calledAt = ! empty($data['called_at'])
            ? Carbon::parse($data['called_at'])
            : now();

        $callLog = CallLog::query()->create([
            'ca_id' => $caId,
            'employee_id' => $employeeId,
            'followup_id' => $followUpId,
            'called_at' => $calledAt,
            'call_status' => $status,
            'call_note' => $note,
            'created_by_user_id' => $actor?->id,
        ]);

        $nextFollowUp = null;
        if ($current) {
            $this->automationService->completeFollowUp($current, $status, $note);
            $nextFollowUp = $this->automationService->createSequenceFollowUp($status, $current->fresh(), $note);
        }

        $this->historyService->record(
            $caId,
            'Call Logged',
            $followUpId,
            $employeeId,
            $status,
            $note,
            ['call_log_id' => $callLog->id, 'called_at' => $calledAt->toIso8601String()],
        );

        $lead = CaMaster::query()->findOrFail($caId);
        $leadUpdates = $this->leadUpdatesForOutcome($status);
        if ($leadUpdates !== []) {
            $lead->update($leadUpdates);
        }

        if ($status === 'Wrong Number') {
            $this->leadQualityHistory->markWrongNumber($lead, 'Call status: Wrong Number', $actor);
        }

        $nextFollowUp = $nextFollowUp ?? null;
        if (($status === 'Follow-up Required' || ! empty($data['next_followup_date'])) && $nextFollowUp === null) {
            if (empty($data['next_followup_date'])) {
                throw new InvalidArgumentException('Follow-up date is required.');
            }

            $scheduled = Carbon::parse(
                $data['next_followup_date'].' '.($data['next_followup_time'] ?? '10:00')
            );

            $nextFollowUp = $this->createFollowUp([
                'ca_id' => $caId,
                'employee_id' => $employeeId,
                'followup_type' => 'Follow Up Reminder',
                'remarks' => $note,
                'scheduled_date' => $scheduled->toDateTimeString(),
                'status' => 'Pending',
                'priority' => 'Normal',
                'source' => 'workflow_call',
                'is_auto_generated' => true,
                'parent_followup_id' => $followUpId,
            ]);

            $this->statusSyncService->apply(
                $lead->fresh(),
                'Follow Up Reminder',
                $this->statusSyncService->workflowExtrasForStatus('Follow Up Reminder'),
            );
        }

        $this->activityLogService->log(
            'FOLLOW_UP_MANAGEMENT',
            'Call Logged',
            (string) $caId,
            $status.' — '.$note,
        );

        $this->forgetCaches($employeeId);

        return [
            'call_log' => $callLog->fresh(['employee']),
            'next_follow_up' => $nextFollowUp,
            'outcome' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function scheduleDemo(array $data, ?User $actor = null): array
    {
        $actor ??= auth()->user();
        $caId = (int) ($data['ca_id'] ?? 0);
        if ($caId <= 0 && ! empty($data['followup_id'])) {
            $caId = (int) FollowUp::query()
                ->where('followup_id', (int) $data['followup_id'])
                ->value('ca_id');
        }
        if ($caId <= 0) {
            throw new InvalidArgumentException('Lead is required.');
        }

        $this->employeeDataScope->ensureCanAccessCaMaster($caId);
        $employeeId = $this->resolveEmployeeId($data, $actor, $caId);

        $demoAt = $this->resolveDemoAt($data);
        $meetingLink = trim((string) ($data['meeting_link'] ?? ''));

        $lead = CaMaster::query()->findOrFail($caId);
        $teamSize = isset($data['team_size']) ? (int) $data['team_size'] : (int) ($lead->team_size ?? 0);

        if (empty($data['skip_conflict_check'])) {
            $availability = app(DemoAvailabilityService::class);
            $provider = $availability->resolveProvider(
                isset($data['demo_provider_id']) ? (int) $data['demo_provider_id'] : null,
                $teamSize > 0 ? $teamSize : null,
                $data['demo_provider_name'] ?? null,
            );
            if ($provider) {
                $check = $availability->checkConflict(array_merge($data, [
                    'demo_at' => $demoAt->toDateTimeString(),
                    'team_size' => $teamSize > 0 ? $teamSize : null,
                    'demo_provider_id' => $provider->id,
                ]));
                if (! $check['available']) {
                    throw new InvalidArgumentException($check['conflict']['message'] ?? 'Demo slot is not available.');
                }
            }
        }

        $followUp = $this->createFollowUp([
            'ca_id' => $caId,
            'employee_id' => $employeeId,
            'followup_type' => 'Demo Scheduled',
            'remarks' => $data['notes'] ?? 'Demo scheduled',
            'scheduled_date' => $demoAt->toDateTimeString(),
            'status' => 'Pending',
            'priority' => 'High',
            'source' => 'workflow_demo',
            'is_auto_generated' => false,
        ]);

        $schedule = DemoSchedule::query()->create([
            'ca_id' => $caId,
            'employee_id' => $employeeId,
            'followup_id' => $followUp->followup_id,
            'call_log_id' => $data['call_log_id'] ?? null,
            'demo_at' => $demoAt,
            'meeting_link' => $meetingLink !== '' ? $meetingLink : '',
            'status' => DemoSchedule::STATUS_SCHEDULED,
            'customer_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
            'created_by_user_id' => $actor?->id,
        ]);

        $lead->update([
            'status' => 'Demo Scheduled',
            'workflow_stage' => 'demo_scheduled',
            'demo_status' => 'scheduled',
        ]);

        $this->statusSyncService->apply(
            $lead->fresh(),
            'Demo Scheduled',
            $this->statusSyncService->workflowExtrasForStatus('Demo Scheduled'),
        );

        $this->historyService->record(
            $caId,
            'Demo Scheduled',
            $followUp->followup_id,
            $employeeId,
            'Demo Scheduled',
            $data['notes'] ?? null,
            [
                'demo_schedule_id' => $schedule->id,
                'demo_at' => $demoAt->toIso8601String(),
                'meeting_link' => $schedule->meeting_link,
            ],
        );

        $this->demoReminderService->scheduleForDemo($schedule->fresh(['lead', 'employee']));
        $this->demoReminderService->processDueReminders();
        $this->forgetCaches($employeeId);

        return [
            'demo_schedule' => $schedule->fresh(['employee', 'lead']),
            'follow_up' => $followUp,
        ];
    }

    /**
     * Resolve an open demo schedule for a follow-up/lead, creating one if needed.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolveDemoSchedule(array $data, ?User $actor = null): DemoSchedule
    {
        $actor ??= auth()->user();
        $followUpId = ! empty($data['followup_id']) ? (int) $data['followup_id'] : null;
        $caId = ! empty($data['ca_id']) ? (int) $data['ca_id'] : 0;
        $followUp = $followUpId ? FollowUp::query()->find($followUpId) : null;

        if ($caId <= 0 && $followUp) {
            $caId = (int) $followUp->ca_id;
        }
        if ($caId <= 0) {
            throw new InvalidArgumentException('Lead is required to update demo result.');
        }

        $this->employeeDataScope->ensureCanAccessCaMaster($caId);

        $openQuery = DemoSchedule::query()
            ->where('ca_id', $caId)
            ->whereDoesntHave('result')
            ->orderByDesc('id');

        if ($followUpId) {
            $byFollowUp = (clone $openQuery)->where('followup_id', $followUpId)->first();
            if ($byFollowUp) {
                return $byFollowUp;
            }
        }

        $existing = $openQuery->first();
        if ($existing) {
            return $existing;
        }

        $employeeId = $this->resolveEmployeeId($data, $actor, $caId, $followUp);
        $lead = CaMaster::query()->findOrFail($caId);
        $demoAt = $followUp?->scheduled_date
            ? Carbon::parse($followUp->scheduled_date)
            : now();

        return DemoSchedule::query()->create([
            'ca_id' => $caId,
            'employee_id' => $employeeId,
            'followup_id' => $followUpId,
            'demo_at' => $demoAt,
            'meeting_link' => $followUp?->meeting_link ?? '',
            'status' => DemoSchedule::STATUS_SCHEDULED,
            'customer_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
            'created_by_user_id' => $actor?->id,
        ]);
    }

    /**
     * Ensure a demo schedule exists for a Demo Scheduled follow-up (employee follow-up path).
     */
    public function ensureDemoScheduleForFollowUp(FollowUp $followUp, ?User $actor = null): ?DemoSchedule
    {
        if ($followUp->followup_type !== 'Demo Scheduled') {
            return null;
        }

        $actor ??= auth()->user();

        $existing = DemoSchedule::query()
            ->where('followup_id', $followUp->followup_id)
            ->whereDoesntHave('result')
            ->first();

        if ($existing) {
            if ($followUp->meeting_link && $existing->meeting_link === '') {
                $existing->update(['meeting_link' => $followUp->meeting_link]);
            }

            $lead = CaMaster::query()->find($followUp->ca_id);
            if ($lead) {
                $this->statusSyncService->apply(
                    $lead,
                    'Demo Scheduled',
                    $this->statusSyncService->workflowExtrasForStatus('Demo Scheduled'),
                );
            }

            return $existing;
        }

        $openForLead = DemoSchedule::query()
            ->where('ca_id', $followUp->ca_id)
            ->whereDoesntHave('result')
            ->orderByDesc('id')
            ->first();

        if ($openForLead && ! $openForLead->followup_id) {
            $openForLead->update([
                'followup_id' => $followUp->followup_id,
                'meeting_link' => $followUp->meeting_link ?: $openForLead->meeting_link,
            ]);

            if ($lead) {
                $this->statusSyncService->apply(
                    $lead,
                    'Demo Scheduled',
                    $this->statusSyncService->workflowExtrasForStatus('Demo Scheduled'),
                );
            }

            return $openForLead->fresh();
        }

        $lead = CaMaster::query()->find($followUp->ca_id);
        $demoAt = $followUp->scheduled_date
            ? Carbon::parse($followUp->scheduled_date)
            : now();
        $employeeId = $followUp->employee_id
            ?? $this->resolveEmployeeId([], $actor, (int) $followUp->ca_id);

        $schedule = DemoSchedule::query()->create([
            'ca_id' => $followUp->ca_id,
            'employee_id' => $employeeId,
            'followup_id' => $followUp->followup_id,
            'demo_at' => $demoAt,
            'meeting_link' => $followUp->meeting_link ?? '',
            'status' => DemoSchedule::STATUS_SCHEDULED,
            'customer_name' => $lead?->ca_name,
            'firm_name' => $lead?->firm_name,
            'created_by_user_id' => $actor?->id,
        ]);

        $lead?->update([
            'status' => 'Demo Scheduled',
            'workflow_stage' => 'demo_scheduled',
            'demo_status' => 'scheduled',
        ]);

        if ($lead) {
            $this->statusSyncService->apply(
                $lead->fresh(),
                'Demo Scheduled',
                $this->statusSyncService->workflowExtrasForStatus('Demo Scheduled'),
            );
        }

        $this->demoReminderService->scheduleForDemo($schedule->fresh(['lead', 'employee']));

        return $schedule;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordDemoResult(int $demoScheduleId, array $data, ?User $actor = null): array
    {
        $actor ??= auth()->user();
        $schedule = DemoSchedule::query()->with(['lead', 'employee', 'result'])->findOrFail($demoScheduleId);
        $this->employeeDataScope->ensureCanAccessCaMaster($schedule->ca_id);

        if ($schedule->result) {
            throw new InvalidArgumentException('Demo result already recorded.');
        }

        $result = (string) ($data['result'] ?? '');
        if (! in_array($result, config('lead_workflow.demo_results', []), true)) {
            throw new InvalidArgumentException('Invalid demo result.');
        }

        $employeeId = $this->resolveEmployeeId($data, $actor, $schedule->ca_id) ?? $schedule->employee_id;
        $nextFollowUp = null;
        $purchase = null;
        $notes = trim((string) ($data['notes'] ?? ''));

        if (in_array($result, ['Next Week', 'Next Month', 'Hold', 'Thinking'], true)) {
            $days = (int) config('lead_workflow.followup_offsets.'.$result, 7);
            $nextFollowUp = $this->createFollowUp([
                'ca_id' => $schedule->ca_id,
                'employee_id' => $employeeId,
                'followup_type' => 'Follow Up Reminder',
                'remarks' => $notes !== '' ? $notes : ('Auto follow-up after demo result: '.$result),
                'scheduled_date' => now()->addDays($days)->setTime(10, 0)->toDateTimeString(),
                'status' => 'Pending',
                'priority' => 'Normal',
                'source' => 'workflow_demo_result',
                'is_auto_generated' => true,
                'parent_followup_id' => $schedule->followup_id,
            ]);
        }

        $demoResult = DemoResult::query()->create([
            'demo_schedule_id' => $schedule->id,
            'ca_id' => $schedule->ca_id,
            'employee_id' => $employeeId,
            'result' => $result,
            'notes' => $data['notes'] ?? null,
            'next_followup_id' => $nextFollowUp?->followup_id,
            'created_by_user_id' => $actor?->id,
        ]);

        $schedule->update(['status' => DemoSchedule::STATUS_COMPLETED]);

        $lead = $schedule->lead;
        $isPurchase = in_array($result, ['Purchased', 'Purchasing'], true);
        $leadUpdates = array_merge(
            [
                'status' => $result,
                'demo_status' => $result,
            ],
            $this->statusSyncService->workflowExtrasForStatus($result),
        );

        if ($isPurchase) {
            $purchaseDate = ! empty($data['purchase_date'])
                ? Carbon::parse($data['purchase_date'])->toDateString()
                : now()->toDateString();

            $assignedBy = AssignmentHistory::query()
                ->where('ca_id', $schedule->ca_id)
                ->orderByDesc('assigned_at')
                ->value('assigned_by');

            $planName = (string) ($data['plan_purchased'] ?? $data['software_name'] ?? 'CRM Annual');

            $purchase = PurchasedCustomer::query()->create([
                'ca_id' => $schedule->ca_id,
                'employee_id' => $employeeId,
                'assigned_by_employee_id' => is_numeric($assignedBy) ? (int) $assignedBy : null,
                'demo_schedule_id' => $schedule->id,
                'demo_result_id' => $demoResult->id,
                'customer_name' => $lead?->ca_name,
                'firm_name' => $lead?->firm_name,
                'mobile_no' => $lead?->mobile_no,
                'email_id' => $lead?->email_id,
                'purchase_date' => $purchaseDate,
                'software_name' => $planName,
                'reference_employee_name' => $schedule->employee?->name,
                'status' => 'Purchased',
                'notes' => $notes !== '' ? $notes : $result,
                'created_by_user_id' => $actor?->id,
            ]);

            $leadUpdates['software_purchased'] = true;
            $leadUpdates['purchase_date'] = $purchaseDate;
        }

        $lead?->update($leadUpdates);

        if ($isPurchase) {
            app(AssignmentRecorder::class)->recordAchievement((int) $schedule->ca_id, (int) $employeeId);
            if (isset($purchase)) {
                app(SalesListService::class)->recordFromPurchase($purchase, $this->salesOverridesFromDemoResult($data, $schedule, $lead));
            }
        }

        $remarkText = $notes !== '' ? $notes : $result;
        $followUpsToComplete = FollowUp::query()
            ->where('ca_id', $schedule->ca_id)
            ->where(function ($query) use ($schedule) {
                if ($schedule->followup_id) {
                    $query->where('followup_id', $schedule->followup_id)
                        ->orWhereIn('followup_type', ['Demo Scheduled', 'Demo Completed']);
                } else {
                    $query->whereIn('followup_type', ['Demo Scheduled', 'Demo Completed']);
                }
            })
            ->get();

        foreach ($followUpsToComplete as $followUp) {
            if (in_array($followUp->status, config('followup_automation.open_statuses', ['Pending', 'Scheduled', 'Open', 'Overdue']), true)) {
                $this->automationService->completeFollowUp($followUp, $result, $remarkText);
            }
            // Always mark as Demo Completed so history appears under that type filter.
            $followUp->refresh();
            $followUp->update([
                'followup_type' => 'Demo Completed',
                'outcome' => $result,
                'remarks' => $remarkText,
                'status' => 'Completed',
            ]);
        }

        $this->demoReminderService->cancelPendingForDemo($schedule->id);

        $this->historyService->record(
            $schedule->ca_id,
            'Demo Result',
            $schedule->followup_id,
            $employeeId,
            $result,
            $data['notes'] ?? null,
            ['demo_result_id' => $demoResult->id],
        );

        $this->forgetCaches($employeeId);

        return [
            'demo_result' => $demoResult,
            'next_follow_up' => $nextFollowUp,
            'purchase' => $purchase,
        ];
    }

    /**
     * Completed demos with results for the Follow-ups Demo History panel.
     *
     * @return list<array<string, mixed>>
     */
    public function demoHistory(?User $actor = null, int $limit = 100): array
    {
        $actor ??= auth()->user();
        $role = $this->rbacService->roleKey($actor);
        $employeeId = $this->employeeDataScope->scopedEmployeeId($actor);

        $query = DemoResult::query()
            ->with([
                'lead:ca_id,ca_name,firm_name,mobile_no,status',
                'employee:employee_id,name',
                'demoSchedule:id,demo_at,meeting_link,status,followup_id,firm_name,customer_name',
            ])
            ->orderByDesc('created_at');

        if ($role === 'employee') {
            $query->where('employee_id', $employeeId);
        }

        return $query->limit($limit)->get()->map(function (DemoResult $row) {
            return [
                'id' => $row->id,
                'demo_schedule_id' => $row->demo_schedule_id,
                'ca_id' => $row->ca_id,
                'firm_name' => $row->lead?->firm_name ?: $row->demoSchedule?->firm_name,
                'customer_name' => $row->lead?->ca_name,
                'mobile_no' => $row->lead?->mobile_no,
                'employee_name' => $row->employee?->name,
                'result' => $row->result,
                'remarks' => $row->notes,
                'demo_at' => $row->demoSchedule?->demo_at?->toIso8601String(),
                'completed_at' => $row->created_at?->toIso8601String(),
                'followup_id' => $row->demoSchedule?->followup_id,
                'meeting_link' => $row->demoSchedule?->meeting_link,
            ];
        })->all();
    }

    /**
     * Backfill older demo follow-ups that were completed without type = Demo Completed.
     */
    public function syncCompletedDemoFollowUpTypes(): int
    {
        $updated = 0;
        DemoResult::query()
            ->with('demoSchedule:id,followup_id,ca_id')
            ->orderBy('id')
            ->chunkById(100, function ($results) use (&$updated) {
                foreach ($results as $result) {
                    $followUpIds = FollowUp::query()
                        ->where('ca_id', $result->ca_id)
                        ->where(function ($query) use ($result) {
                            $query->whereIn('followup_type', ['Demo Scheduled', 'Demo Completed']);
                            if ($result->demoSchedule?->followup_id) {
                                $query->orWhere('followup_id', $result->demoSchedule->followup_id);
                            }
                        })
                        ->pluck('followup_id');

                    if ($followUpIds->isEmpty()) {
                        continue;
                    }

                    $updated += FollowUp::query()
                        ->whereIn('followup_id', $followUpIds)
                        ->update([
                            'followup_type' => 'Demo Completed',
                            'outcome' => $result->result,
                            'remarks' => $result->notes ?: $result->result,
                            'status' => 'Completed',
                        ]);
                }
            });

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function lists(?User $actor = null): array
    {
        $actor ??= auth()->user();
        $role = $this->rbacService->roleKey($actor);
        $employeeId = $this->employeeDataScope->scopedEmployeeId($actor);

        $demoQuery = DemoSchedule::query()
            ->with(['lead:ca_id,ca_name,firm_name,mobile_no,email_id,status', 'employee:employee_id,name'])
            ->where('status', DemoSchedule::STATUS_SCHEDULED)
            ->orderBy('demo_at');

        $purchaseQuery = PurchasedCustomer::query()
            ->with(['employee:employee_id,name', 'assignedBy:employee_id,name'])
            ->orderByDesc('purchase_date');

        $interestedQuery = DemoResult::query()
            ->with(['lead:ca_id,ca_name,firm_name,mobile_no,status', 'employee:employee_id,name'])
            ->where('result', 'Interested')
            ->orderByDesc('created_at');

        $notInterestedQuery = DemoResult::query()
            ->with(['lead:ca_id,ca_name,firm_name,mobile_no,status', 'employee:employee_id,name'])
            ->where('result', 'Not Interested')
            ->orderByDesc('created_at');

        $holdQuery = DemoResult::query()
            ->with(['lead:ca_id,ca_name,firm_name,mobile_no,status', 'employee:employee_id,name'])
            ->whereIn('result', ['Hold', 'Next Week', 'Next Month'])
            ->orderByDesc('created_at');

        $callsTodayQuery = CallLog::query()
            ->with(['lead:ca_id,ca_name,firm_name,mobile_no', 'employee:employee_id,name'])
            ->whereDate('called_at', now()->toDateString())
            ->orderByDesc('called_at');

        if ($role === 'employee') {
            $demoQuery->where('employee_id', $employeeId);
            $interestedQuery->where('employee_id', $employeeId);
            $notInterestedQuery->where('employee_id', $employeeId);
            $holdQuery->where('employee_id', $employeeId);
            $callsTodayQuery->where('employee_id', $employeeId);
            $purchases = collect();
            $purchaseCount = 0;
        } else {
            $purchaseCount = (clone $purchaseQuery)->count();
            $purchases = (clone $purchaseQuery)->limit(100)->get();
        }

        $performance = $role !== 'employee' ? $this->employeePerformance() : [];

        return [
            'demo_scheduled' => (clone $demoQuery)->limit(100)->get(),
            'interested' => (clone $interestedQuery)->limit(100)->get(),
            'not_interested' => (clone $notInterestedQuery)->limit(100)->get(),
            'hold' => (clone $holdQuery)->limit(100)->get(),
            'purchased' => $purchases,
            'todays_calls' => (clone $callsTodayQuery)->limit(100)->get(),
            'employee_performance' => $performance,
            'can_view_purchased' => $role !== 'employee',
            'can_view_all_demos' => $role !== 'employee',
            'counts' => [
                'demo_scheduled' => (clone $demoQuery)->count(),
                'interested' => (clone $interestedQuery)->count(),
                'not_interested' => (clone $notInterestedQuery)->count(),
                'hold' => (clone $holdQuery)->count(),
                'purchased' => $purchaseCount,
                'todays_calls' => (clone $callsTodayQuery)->count(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employeePerformance(): array
    {
        $calls = CallLog::query()
            ->selectRaw('employee_id, COUNT(*) as calls')
            ->whereNotNull('employee_id')
            ->groupBy('employee_id')
            ->pluck('calls', 'employee_id');

        $demos = DemoSchedule::query()
            ->selectRaw('employee_id, COUNT(*) as demos')
            ->whereNotNull('employee_id')
            ->groupBy('employee_id')
            ->pluck('demos', 'employee_id');

        $purchases = PurchasedCustomer::query()
            ->selectRaw('employee_id, COUNT(*) as purchases')
            ->whereNotNull('employee_id')
            ->groupBy('employee_id')
            ->pluck('purchases', 'employee_id');

        return \App\Models\Employee::query()
            ->where('status', 'Active')
            ->orderBy('name')
            ->get(['employee_id', 'name'])
            ->map(fn ($employee) => [
                'employee_id' => $employee->employee_id,
                'name' => $employee->name,
                'calls' => (int) ($calls[$employee->employee_id] ?? 0),
                'demos' => (int) ($demos[$employee->employee_id] ?? 0),
                'purchases' => (int) ($purchases[$employee->employee_id] ?? 0),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createFollowUp(array $data): FollowUp
    {
        $followUp = FollowUp::query()->create([
            'ca_id' => $data['ca_id'],
            'employee_id' => $data['employee_id'],
            'created_by_user_id' => auth()->id(),
            'followup_type' => $data['followup_type'],
            'outcome' => $data['outcome'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'scheduled_date' => $data['scheduled_date'],
            'next_followup_date' => $data['next_followup_date'] ?? null,
            'status' => $data['status'] ?? 'Pending',
            'priority' => $data['priority'] ?? 'Normal',
            'parent_followup_id' => $data['parent_followup_id'] ?? null,
            'sequence_step' => $data['sequence_step'] ?? null,
            'is_auto_generated' => (bool) ($data['is_auto_generated'] ?? false),
            'source' => $data['source'] ?? 'workflow',
        ]);

        $this->automationService->afterFollowUpCreated($followUp);

        return $followUp->fresh(['caMaster', 'employee']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveDemoAt(array $data): Carbon
    {
        if (! empty($data['demo_at'])) {
            $demoAt = Carbon::parse($data['demo_at']);
        } elseif (! empty($data['demo_date'])) {
            $demoAt = Carbon::parse(
                $data['demo_date'].' '.($data['demo_time'] ?? '10:00')
            );
        } else {
            throw new InvalidArgumentException('Demo date is required.');
        }

        return $demoAt;
    }

    /**
     * @return array<string, mixed>
     */
    private function leadUpdatesForOutcome(string $status): array
    {
        $crmStatus = $this->statusSyncService->statusForCallOutcome($status);
        $extras = $crmStatus
            ? $this->statusSyncService->workflowExtrasForStatus($crmStatus)
            : [];

        return match ($status) {
            'Demo Scheduled' => array_merge([
                'status' => 'Demo Scheduled',
                'call_status' => $status,
                'demo_status' => 'scheduled',
                'workflow_stage' => 'demo_scheduled',
            ], $extras),
            'Follow-up Required' => [
                'status' => 'Follow Up Scheduled',
                'call_status' => $status,
                'workflow_stage' => 'follow_up',
            ],
            'Interested' => [
                'status' => 'Interested',
                'call_status' => $status,
                'workflow_stage' => 'interested',
            ],
            'Not Interested' => [
                'status' => 'Not Interested',
                'call_status' => $status,
                'workflow_stage' => 'not_interested',
            ],
            'Wrong Number' => [
                'call_status' => $status,
                'workflow_stage' => 'wrong_number',
            ],
            default => $crmStatus
                ? array_merge(['status' => $crmStatus, 'call_status' => $status], $extras)
                : [
                    'call_status' => $status,
                    'workflow_stage' => 'called',
                ],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveEmployeeId(
        array $data,
        ?User $actor,
        ?int $caId = null,
        ?FollowUp $followUp = null,
    ): ?int {
        if (! empty($data['employee_id'])) {
            return (int) $data['employee_id'];
        }

        if ($followUp?->employee_id) {
            return (int) $followUp->employee_id;
        }

        if ($caId) {
            $assigned = LeadAssignmentEngine::query()
                ->where('ca_id', $caId)
                ->where('status', 'Active')
                ->value('employee_id');
            if ($assigned) {
                return (int) $assigned;
            }
        }

        $scoped = $this->employeeDataScope->scopedEmployeeId($actor);
        if ($scoped !== null && $scoped > 0) {
            return $scoped;
        }

        return $this->employeeDataScope->resolveEmployeeId($actor);
    }

    private function forgetCaches(?int $employeeId): void
    {
        if ($employeeId) {
            $this->cacheService->forgetDailyEmployeeTargets($employeeId);
            $this->cacheService->forgetYearlyEmployeeTargets($employeeId);
        }
        $this->cacheService->forgetDashboardMetrics();
        $this->cacheService->forgetLeadSegmentCounts();
        $this->cacheService->forgetPipelineStageCounts();
        $this->cacheService->forgetEmployeeRankings();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function salesOverridesFromDemoResult(array $data, DemoSchedule $schedule, ?CaMaster $lead): array
    {
        $plans = array_keys(config('sales_plans.plans', []));
        $planName = (string) ($data['plan_purchased'] ?? $data['software_name'] ?? config('sales_plans.default_plan', 'CRM Annual'));
        if (! in_array($planName, $plans, true)) {
            $planName = config('sales_plans.default_plan', 'CRM Annual');
        }

        $overrides = [
            'plan_purchased' => $planName,
            'purchase_date' => $data['purchase_date'] ?? now()->toDateString(),
            'customer_name' => $data['customer_name'] ?? $lead?->ca_name,
            'firm_name' => $data['firm_name'] ?? $lead?->firm_name,
            'reference_name' => $data['reference_name'] ?? $schedule->employee?->name,
            'mobile_no' => $data['mobile_no'] ?? $lead?->mobile_no,
            'city_name' => $data['city_name'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        foreach (['points', 'cooling_period_days', 'total_amount', 'amount_received', 'employee_id', 'manager_id', 'invoice_number'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $overrides[$field] = $data[$field];
            }
        }

        return $overrides;
    }
}
