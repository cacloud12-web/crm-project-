<?php

namespace App\Services\FollowUp;

use App\Models\FollowUp;
use App\Models\FollowUpReminder;
use App\Models\Task;
use App\Services\Activity\ActivityLogService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;

class FollowUpReminderService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly FollowUpHistoryService $historyService,
        private readonly NotificationService $notificationService,
    ) {}

    public function scheduleForFollowUp(FollowUp $followUp, ?Task $task = null): void
    {
        if (! $followUp->scheduled_date) {
            return;
        }

        $scheduledAt = $followUp->scheduled_date instanceof Carbon
            ? $followUp->scheduled_date->copy()
            : Carbon::parse($followUp->scheduled_date);

        foreach (config('followup_automation.reminder_offsets', []) as $offset) {
            $minutes = (int) ($offset['minutes_before'] ?? 0);
            $type = (string) ($offset['type'] ?? 'reminder');
            $remindAt = $scheduledAt->copy()->subMinutes($minutes);

            if ($remindAt->isPast()) {
                continue;
            }

            FollowUpReminder::query()->firstOrCreate(
                [
                    'followup_id' => $followUp->followup_id,
                    'reminder_type' => $type,
                ],
                [
                    'task_id' => $task?->task_id,
                    'employee_id' => $followUp->employee_id,
                    'remind_at' => $remindAt,
                    'status' => 'Pending',
                ],
            );
        }

        $this->historyService->record(
            $followUp->ca_id,
            'Reminder Generated',
            $followUp->followup_id,
            $followUp->employee_id,
            null,
            'Follow-up reminders scheduled',
            ['scheduled_at' => $scheduledAt->toIso8601String()],
        );

        $this->activityLogService->log(
            'FOLLOW_UP_MANAGEMENT',
            'Reminder Generated',
            (string) $followUp->followup_id,
            'Reminders scheduled for '.$scheduledAt->toDateTimeString(),
        );
    }

    public function cancelPendingForFollowUp(int $followupId): void
    {
        FollowUpReminder::query()
            ->where('followup_id', $followupId)
            ->where('status', 'Pending')
            ->update(['status' => 'Cancelled']);
    }

    public function processDueReminders(): int
    {
        $sent = 0;
        $now = now();

        FollowUpReminder::query()
            ->with(['followUp.caMaster:ca_id,firm_name', 'followUp.employee:employee_id,email_id'])
            ->where('status', 'Pending')
            ->where('remind_at', '<=', $now)
            ->orderBy('reminder_id')
            ->chunkById(100, function ($reminders) use (&$sent) {
                foreach ($reminders as $reminder) {
                    if ($this->dispatchReminder($reminder)) {
                        $sent++;
                    }
                }
            }, 'reminder_id');

        return $sent;
    }

    private function dispatchReminder(FollowUpReminder $reminder): bool
    {
        $followUp = $reminder->followUp;
        if (! $followUp) {
            $reminder->update(['status' => 'Cancelled']);

            return false;
        }

        $firm = $followUp->caMaster?->firm_name ?? 'Lead #'.$followUp->ca_id;
        $title = match ($reminder->reminder_type) {
            '1_day_before' => 'Follow-up reminder — tomorrow',
            '1_hour_before' => 'Follow-up reminder — 1 hour',
            '15_minutes_before' => 'Follow-up reminder — 15 minutes',
            default => 'Follow-up reminder',
        };
        $message = $firm.' — '.$followUp->followup_type.' at '.$followUp->scheduled_date?->format('d M Y H:i');
        $dedupKey = 'followup_reminder:'.$reminder->reminder_id;

        $userId = $this->notificationService->resolveUserIdByEmployeeEmail(
            $followUp->employee?->email_id,
        );

        $result = $userId
            ? $this->notificationService->notifyUser($userId, 'followup_reminder', $title, $message, [
                'entity_type' => 'follow_up',
                'entity_id' => (string) $followUp->followup_id,
                'dedup_key' => $dedupKey,
                'payload' => [
                    'reminder_type' => $reminder->reminder_type,
                    'ca_id' => $followUp->ca_id,
                ],
            ])
            : $this->notificationService->notifyManagement('followup_reminder', $title, $message, [
                'entity_type' => 'follow_up',
                'entity_id' => (string) $followUp->followup_id,
                'dedup_key' => $dedupKey,
            ]);

        $reminder->update([
            'status' => 'Sent',
            'sent_at' => now(),
        ]);

        $this->historyService->record(
            $followUp->ca_id,
            'Reminder Sent',
            $followUp->followup_id,
            $followUp->employee_id,
            null,
            $reminder->reminder_type,
            ['reminder_id' => $reminder->reminder_id],
        );

        return (bool) $result;
    }
}
