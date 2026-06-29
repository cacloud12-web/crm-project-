<?php

namespace App\Services\FollowUp;

use App\Models\FollowUp;
use App\Services\Activity\ActivityLogService;
use App\Services\Concerns\SearchesListings;
use App\Services\DemoConfirmation\DemoConfirmationService;
use App\Services\Notifications\NotificationService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class FollowUpService
{
    use SearchesListings;

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        private readonly DemoConfirmationService $demoConfirmationService,
        private readonly FollowUpAutomationService $automationService,
        private readonly FollowUpHistoryService $historyService,
        private readonly RbacService $rbacService,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(
            FollowUp::query()->with(['caMaster.city', 'employee']),
            $params,
            'follow_ups',
        );
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(
            FollowUp::query()->with(['caMaster.city', 'employee']),
            [],
            'follow_ups',
        );
    }

    public function find(int|string $id): FollowUp
    {
        app(EmployeeDataScopeService::class)->ensureCanAccessFollowUp($id);

        return FollowUp::query()
            ->with(['caMaster.city', 'employee'])
            ->findOrFail($id);
    }

    public function create(array $data): FollowUp
    {
        $followUp = FollowUp::create([
            'ca_id' => $data['ca_id'],
            'employee_id' => $data['employee_id'] ?? null,
            'created_by_user_id' => $data['created_by_user_id'] ?? auth()->id(),
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

        return $followUp;
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

        $followUp->update([
            'ca_id' => $data['ca_id'] ?? $followUp->ca_id,
            'employee_id' => $data['employee_id'] ?? $followUp->employee_id,
            'followup_type' => $data['followup_type'] ?? $followUp->followup_type,
            'outcome' => $data['outcome'] ?? $followUp->outcome,
            'remarks' => $data['remarks'] ?? $followUp->remarks,
            'scheduled_date' => $data['scheduled_date'] ?? $followUp->scheduled_date,
            'next_followup_date' => $data['next_followup_date'] ?? $followUp->next_followup_date,
            'status' => $data['status'] ?? $followUp->status,
            'priority' => $data['priority'] ?? $followUp->priority,
        ]);

        $followUp = $followUp->fresh(['caMaster.city', 'employee']);
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

        $followUp->delete();
    }

    public function historyForLead(int $caId): Collection
    {
        return $this->historyService->timelineForLead($caId);
    }
}
