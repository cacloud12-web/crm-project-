<?php

namespace App\Services\FollowUp;

use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Services\Activity\ActivityLogService;
use App\Services\Cache\CrmCacheService;
use App\Services\Concerns\SearchesListings;
use App\Services\DemoConfirmation\DemoConfirmationService;
use App\Services\FollowUp\Concerns\ResolvesFollowUpDemoFields;
use App\Services\Leads\CaMasterStatusSyncService;
use App\Services\Notifications\NotificationService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class FollowUpService
{
    use ResolvesFollowUpDemoFields;
    use SearchesListings;

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        private readonly DemoConfirmationService $demoConfirmationService,
        private readonly FollowUpAutomationService $automationService,
        private readonly FollowUpHistoryService $historyService,
        private readonly RbacService $rbacService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly CrmCacheService $cacheService,
        private readonly CaMasterStatusSyncService $statusSyncService,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(
            FollowUp::query()->with($this->listingRelations()),
            $params,
            'follow_ups',
        );
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(
            FollowUp::query()->with($this->listingRelations()),
            [],
            'follow_ups',
        );
    }

    public function find(int|string $id): FollowUp
    {
        app(EmployeeDataScopeService::class)->ensureCanAccessFollowUp($id);

        return FollowUp::query()
            ->with($this->listingRelations())
            ->findOrFail($id);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function listingRelations(): array
    {
        return [
            'caMaster:ca_id,firm_name',
            'employee:employee_id,name',
        ];
    }

    public function create(array $data): FollowUp
    {
        app(EmployeeDataScopeService::class)->ensureCanAccessCaMaster((int) $data['ca_id']);

        $demoFields = $this->resolveFollowUpDemoFields($data);

        $followUp = FollowUp::create([
            'ca_id' => $data['ca_id'],
            'employee_id' => $this->resolveFollowUpEmployeeId($data),
            'created_by_user_id' => $data['created_by_user_id'] ?? auth()->id(),
            'followup_type' => $data['followup_type'],
            'outcome' => $data['outcome'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'scheduled_date' => $data['scheduled_date'],
            'next_followup_date' => $data['next_followup_date'] ?? null,
            'status' => $data['status'] ?? 'Pending',
            'priority' => $data['priority'] ?? 'Normal',
            'team_size' => $demoFields['team_size'],
            'demo_provider_name' => $demoFields['demo_provider_name'],
            'meeting_link' => $demoFields['meeting_link'],
            'parent_followup_id' => $data['parent_followup_id'] ?? null,
            'sequence_step' => $data['sequence_step'] ?? null,
            'is_auto_generated' => (bool) ($data['is_auto_generated'] ?? false),
            'source' => $data['source'] ?? 'manual',
        ]);

        $followUp->load('caMaster:ca_id,firm_name');
        $firm = $followUp->caMaster?->firm_name ?? 'Lead #'.$followUp->ca_id;

        $this->activityLogService->log(
            'FOLLOW_UP_MANAGEMENT',
            'Follow-up Create',
            (string) $followUp->followup_id,
            $followUp->followup_type.' · '.$firm,
        );

        $this->notifyIfDue($followUp, $firm);
        $this->demoConfirmationService->handleFollowUpCreated($followUp);
        $this->automationService->afterFollowUpCreated($followUp);

        if ($followUp->followup_type === 'Demo Scheduled') {
            app(\App\Services\Workflow\LeadWorkflowService::class)
                ->ensureDemoScheduleForFollowUp($followUp->fresh());
        } else {
            $this->syncMasterStatusFromFollowUp($followUp);
        }

        $this->forgetFollowUpCaches($followUp);

        return $followUp;
    }

    private function forgetFollowUpCaches(FollowUp $followUp): void
    {
        if ($followUp->employee_id) {
            $this->cacheService->forgetEmployeeDashboard((int) $followUp->employee_id);
        }

        $actorEmployeeId = $this->employeeDataScope->resolveEmployeeId(auth()->user());
        if ($actorEmployeeId && (int) $actorEmployeeId !== (int) $followUp->employee_id) {
            $this->cacheService->forgetEmployeeDashboard((int) $actorEmployeeId);
        }

        $this->cacheService->forgetDashboardMetrics();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveFollowUpEmployeeId(array $data): ?int
    {
        if (! empty($data['employee_id'])) {
            return (int) $data['employee_id'];
        }

        $user = auth()->user();
        $scopedId = $this->employeeDataScope->scopedEmployeeId($user);
        if ($scopedId !== null && $scopedId > 0) {
            return $scopedId;
        }

        $resolvedId = $this->employeeDataScope->resolveEmployeeId($user);
        if ($resolvedId) {
            return $resolvedId;
        }

        if (! empty($data['ca_id'])) {
            $assigneeId = LeadAssignmentEngine::query()
                ->where('ca_id', (int) $data['ca_id'])
                ->where('status', 'Active')
                ->value('employee_id');

            if ($assigneeId) {
                return (int) $assigneeId;
            }
        }

        return null;
    }

    private function notifyIfDue(FollowUp $followUp, string $firm): void
    {
        if ($followUp->status !== 'Pending' || ! $followUp->scheduled_date) {
            return;
        }

        if ($followUp->scheduled_date->toDateString() > now()->toDateString()) {
            return;
        }

        $today = now()->toDateString();
        $title = 'Follow-up due';
        $message = $firm.' — '.$followUp->followup_type;
        $extra = [
            'entity_type' => 'follow_up',
            'entity_id' => (string) $followUp->followup_id,
            'dedup_key' => 'followup_due:'.$followUp->followup_id.':'.$today,
            'payload' => [
                'ca_id' => $followUp->ca_id,
                'scheduled_date' => $followUp->scheduled_date->toDateString(),
            ],
        ];

        $followUp->loadMissing('employee:employee_id,email_id');
        $userId = $this->notificationService->resolveUserIdByEmployeeEmail($followUp->employee?->email_id);

        if ($userId) {
            $this->notificationService->notifyUser($userId, 'followup_due', $title, $message, $extra);

            return;
        }

        $this->notificationService->notifyManagement('followup_due', $title, $message, $extra);
    }

    public function update(FollowUp $followUp, array $data): FollowUp
    {
        $previousScheduledDate = $followUp->scheduled_date?->copy();
        $previousFollowupType = $followUp->followup_type;
        $rescheduleReason = $data['reschedule_reason'] ?? null;

        $newScheduled = isset($data['scheduled_date'])
            ? Carbon::parse($data['scheduled_date'])
            : $followUp->scheduled_date;

        if ($previousScheduledDate && $newScheduled && ! $previousScheduledDate->equalTo($newScheduled)) {
            $this->automationService->handleReschedule(
                $followUp,
                $previousScheduledDate,
                $newScheduled,
                $rescheduleReason,
            );
        }

        $followUp->update(array_merge([
            'ca_id' => $data['ca_id'] ?? $followUp->ca_id,
            'employee_id' => $data['employee_id'] ?? $followUp->employee_id,
            'followup_type' => $data['followup_type'] ?? $followUp->followup_type,
            'outcome' => $data['outcome'] ?? $followUp->outcome,
            'remarks' => $data['remarks'] ?? $followUp->remarks,
            'scheduled_date' => $data['scheduled_date'] ?? $followUp->scheduled_date,
            'next_followup_date' => $data['next_followup_date'] ?? $followUp->next_followup_date,
            'status' => $data['status'] ?? $followUp->status,
            'priority' => $data['priority'] ?? $followUp->priority,
        ], $this->resolveFollowUpDemoFields($data, $followUp)));

        $followUp = $followUp->fresh($this->listingRelations());
        $firm = $followUp->caMaster?->firm_name ?? 'Lead #'.$followUp->ca_id;

        $this->activityLogService->log(
            'FOLLOW_UP_MANAGEMENT',
            'Follow-up Update',
            (string) $followUp->followup_id,
            $followUp->followup_type.' · '.$firm,
        );

        $this->historyService->record(
            $followUp->ca_id,
            'Follow-up Updated',
            $followUp->followup_id,
            $followUp->employee_id,
            $followUp->outcome,
            $followUp->remarks,
        );

        $this->demoConfirmationService->handleFollowUpUpdated(
            $followUp,
            $previousScheduledDate,
            $previousFollowupType,
        );

        if ($followUp->followup_type === 'Demo Scheduled') {
            app(\App\Services\Workflow\LeadWorkflowService::class)
                ->ensureDemoScheduleForFollowUp($followUp->fresh());
        } else {
            $this->syncMasterStatusFromFollowUp($followUp);
        }

        $this->forgetFollowUpCaches($followUp);

        return $followUp;
    }

    public function delete(FollowUp $followUp): void
    {
        $user = auth()->user();
        $role = $user ? $this->rbacService->userPayload($user)['role'] ?? 'employee' : 'employee';

        if (in_array($role, ['employee'], true)) {
            throw new InvalidArgumentException('Employees cannot delete follow-up records. Contact your manager.');
        }

        $followUp->load('caMaster:ca_id,firm_name');
        $firm = $followUp->caMaster?->firm_name ?? 'Lead #'.$followUp->ca_id;

        $this->activityLogService->log(
            'FOLLOW_UP_MANAGEMENT',
            'Follow-up Delete',
            (string) $followUp->followup_id,
            $followUp->followup_type.' · '.$firm,
        );

        $this->forgetFollowUpCaches($followUp);
        $followUp->delete();
    }

    public function historyForLead(int $caId): Collection
    {
        return $this->historyService->timelineForLead($caId);
    }

    private function syncMasterStatusFromFollowUp(FollowUp $followUp): void
    {
        $status = $this->statusSyncService->statusForFollowUp($followUp);
        if (! $status) {
            return;
        }

        $lead = $followUp->caMaster;
        if (! $lead) {
            $lead = \App\Models\CaMaster::query()->find($followUp->ca_id);
        }

        if ($lead) {
            $this->statusSyncService->apply(
                $lead,
                $status,
                $this->statusSyncService->workflowExtrasForStatus($status),
            );
        }
    }
}
