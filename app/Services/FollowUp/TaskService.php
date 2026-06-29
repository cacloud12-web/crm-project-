<?php

namespace App\Services\FollowUp;

use App\Models\FollowUp;
use App\Models\Task;
use App\Services\Activity\ActivityLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TaskService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly FollowUpHistoryService $historyService,
    ) {}

    public function createForFollowUp(FollowUp $followUp, string $source = 'Auto Generated'): Task
    {
        $scheduled = $followUp->scheduled_date ?? now();
        $dueDate = $scheduled instanceof Carbon ? $scheduled->toDateString() : Carbon::parse($scheduled)->toDateString();
        $dueTime = $scheduled instanceof Carbon ? $scheduled->format('H:i:s') : Carbon::parse($scheduled)->format('H:i:s');

        $task = Task::query()->create([
            'followup_id' => $followUp->followup_id,
            'ca_id' => $followUp->ca_id,
            'employee_id' => $followUp->employee_id,
            'task_type' => $this->taskTypeForFollowUp($followUp),
            'due_date' => $dueDate,
            'due_time' => $dueTime,
            'priority' => $followUp->priority ?? 'Normal',
            'status' => 'Pending',
            'task_source' => $source,
            'remarks' => $followUp->remarks,
        ]);

        $followUp->loadMissing('caMaster:ca_id,firm_name');
        $firm = $followUp->caMaster?->firm_name ?? 'Lead #'.$followUp->ca_id;

        $this->activityLogService->log(
            'FOLLOW_UP_MANAGEMENT',
            'Task Created',
            (string) $task->task_id,
            $task->task_type.' · '.$firm,
        );

        $this->historyService->record(
            $followUp->ca_id,
            'Task Created',
            $followUp->followup_id,
            $followUp->employee_id,
            null,
            $task->task_type,
            [
                'task_id' => $task->task_id,
                'due_date' => $dueDate,
                'due_time' => $dueTime,
                'priority' => $task->priority,
            ],
        );

        return $task;
    }

    public function complete(Task $task): Task
    {
        $task->update([
            'status' => 'Completed',
            'completed_at' => now(),
        ]);

        $task->loadMissing('caMaster:ca_id,firm_name');
        $firm = $task->caMaster?->firm_name ?? 'Lead #'.$task->ca_id;

        $this->activityLogService->log(
            'FOLLOW_UP_MANAGEMENT',
            'Task Completed',
            (string) $task->task_id,
            $task->task_type.' · '.$firm,
        );

        $this->historyService->record(
            $task->ca_id,
            'Task Completed',
            $task->followup_id,
            $task->employee_id,
            null,
            $task->task_type,
            ['task_id' => $task->task_id],
        );

        return $task->fresh();
    }

    public function syncFromFollowUp(FollowUp $followUp): void
    {
        $scheduled = $followUp->scheduled_date;
        if (! $scheduled) {
            return;
        }

        Task::query()
            ->where('followup_id', $followUp->followup_id)
            ->where('status', 'Pending')
            ->update([
                'due_date' => $scheduled->toDateString(),
                'due_time' => $scheduled->format('H:i:s'),
                'priority' => $followUp->priority ?? 'Normal',
            ]);
    }

    public function listForEmployee(?int $employeeId, array $params = []): Collection
    {
        $query = Task::query()
            ->with(['caMaster:ca_id,firm_name', 'followUp:followup_id,followup_type,status'])
            ->orderBy('due_date')
            ->orderBy('due_time');

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }

        $status = (string) ($params['status'] ?? '');
        if ($status !== '') {
            $query->where('status', $status);
        }

        if (! empty($params['overdue'])) {
            $query->where('status', 'Pending')
                ->whereDate('due_date', '<', now()->toDateString());
        }

        return $query->limit((int) ($params['limit'] ?? 50))->get();
    }

    public function markOverdue(): int
    {
        return Task::query()
            ->where('status', 'Pending')
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => 'Overdue']);
    }

    private function taskTypeForFollowUp(FollowUp $followUp): string
    {
        return match ($followUp->followup_type) {
            'Demo Scheduled', 'Demo Completed' => $followUp->followup_type,
            default => 'Follow-up Call',
        };
    }
}
