<?php

namespace App\Services\Demo;

use App\Models\DemoProvider;
use App\Models\DemoSchedule;
use App\Models\DemoScheduleHistory;
use App\Models\Employee;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Workflow\DemoReminderService;
use App\Services\Workflow\LeadWorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DemoScheduleCalendarService
{
    public function __construct(
        private readonly DemoAvailabilityService $availabilityService,
        private readonly DemoCalendarService $calendarService,
        private readonly LeadWorkflowService $workflowService,
        private readonly DemoReminderService $demoReminderService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function schedule(array $data, ?User $actor = null): array
    {
        $actor ??= auth()->user();
        $provider = $this->availabilityService->resolveProvider(
            isset($data['demo_provider_id']) ? (int) $data['demo_provider_id'] : null,
            isset($data['team_size']) ? (int) $data['team_size'] : null,
            $data['demo_provider_name'] ?? null,
        );
        if (! $provider) {
            throw new InvalidArgumentException('Demo provider is required.');
        }
        [$start, $end] = $this->availabilityService->resolveWindow($data, $provider);
        app(DemoSchedulingRulesService::class)->validate(
            $start->toDateString(),
            $start->format('H:i'),
            $end->format('H:i'),
        );

        $check = $this->availabilityService->checkConflict($data);
        if (! $check['available']) {
            throw new InvalidArgumentException($check['conflict']['message'] ?? 'Demo slot is not available.');
        }

        $payload = array_merge($data, [
            'demo_at' => $start->toDateTimeString(),
            'demo_end_at' => $end->toDateTimeString(),
            'meeting_link' => $data['meeting_link'] ?? $provider->default_meeting_link,
        ]);

        return DB::transaction(function () use ($payload, $provider, $actor, $start, $end) {
            $recheck = $this->availabilityService->checkConflict($payload);
            if (! $recheck['available']) {
                throw new InvalidArgumentException($recheck['conflict']['message'] ?? 'Demo slot is not available.');
            }

            $payload['skip_conflict_check'] = true;
            $result = $this->workflowService->scheduleDemo($payload, $actor);
            /** @var DemoSchedule $schedule */
            $schedule = $result['demo_schedule'];
            $schedule->update([
                'demo_provider_id' => $provider->id,
                'demo_provider_name' => $provider->name,
                'demo_end_at' => $end,
                'team_size' => $payload['team_size'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'manager_id' => $payload['manager_id'] ?? null,
                'updated_by_user_id' => $actor?->id,
            ]);

            $this->recordHistory($schedule, 'scheduled', null, $schedule->fresh()->toArray(), $actor, 'Demo scheduled from calendar');
            $this->notifyScheduled($schedule->fresh(['employee', 'provider', 'lead']), $actor);

            return [
                'demo_schedule' => $schedule->fresh(['employee', 'provider', 'lead']),
                'follow_up' => $result['follow_up'] ?? null,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reschedule(DemoSchedule $schedule, array $data, ?User $actor = null): DemoSchedule
    {
        $actor ??= auth()->user();
        if (! $this->calendarService->canEditSchedule($actor, $schedule)) {
            throw new InvalidArgumentException('You do not have permission to reschedule this demo.');
        }

        $payload = array_merge($data, [
            'demo_provider_id' => $data['demo_provider_id'] ?? $schedule->demo_provider_id,
            'team_size' => $data['team_size'] ?? $schedule->team_size,
        ]);

        $provider = $this->availabilityService->resolveProvider(
            (int) ($payload['demo_provider_id'] ?? 0),
            isset($payload['team_size']) ? (int) $payload['team_size'] : null,
            $payload['demo_provider_name'] ?? $schedule->demo_provider_name,
        ) ?? $schedule->provider;

        if (! $provider) {
            throw new InvalidArgumentException('Demo provider is required.');
        }

        [$start, $end] = $this->availabilityService->resolveWindow($payload, $provider);
        app(DemoSchedulingRulesService::class)->validate(
            $start->toDateString(),
            $start->format('H:i'),
            $end->format('H:i'),
        );

        $check = $this->availabilityService->checkConflict($payload, $schedule->id);
        if (! $check['available']) {
            throw new InvalidArgumentException($check['conflict']['message'] ?? 'Demo slot is not available.');
        }

        $before = $schedule->toArray();

        return DB::transaction(function () use ($schedule, $provider, $start, $end, $payload, $actor, $before) {
            $schedule->update([
                'demo_at' => $start,
                'demo_end_at' => $end,
                'demo_provider_id' => $provider->id,
                'demo_provider_name' => $provider->name,
                'meeting_link' => $payload['meeting_link'] ?? $schedule->meeting_link,
                'notes' => $payload['notes'] ?? $schedule->notes,
                'status' => DemoSchedule::STATUS_RESCHEDULED,
                'updated_by_user_id' => $actor?->id,
            ]);

            if ($schedule->followup_id) {
                $schedule->followUp?->update([
                    'scheduled_date' => $start,
                    'meeting_link' => $schedule->meeting_link,
                    'demo_provider_name' => $provider->name,
                ]);
            }

            $this->demoReminderService->cancelPendingForDemo($schedule->id);
            $this->demoReminderService->scheduleForDemo($schedule->fresh(['lead', 'employee']));
            $this->recordHistory($schedule, 'rescheduled', $before, $schedule->fresh()->toArray(), $actor);
            $this->notifyRescheduled($schedule->fresh(['employee', 'provider']), $actor);

            return $schedule->fresh(['employee', 'provider', 'lead']);
        });
    }

    public function cancel(DemoSchedule $schedule, ?string $reason, ?User $actor = null): DemoSchedule
    {
        $actor ??= auth()->user();
        if (! $this->calendarService->canEditSchedule($actor, $schedule)) {
            throw new InvalidArgumentException('You do not have permission to cancel this demo.');
        }

        $before = $schedule->toArray();
        $schedule->update([
            'status' => DemoSchedule::STATUS_CANCELLED,
            'notes' => trim(($schedule->notes ? $schedule->notes."\n" : '').($reason ?? 'Cancelled')),
            'updated_by_user_id' => $actor?->id,
        ]);
        $this->demoReminderService->cancelPendingForDemo($schedule->id);
        $this->recordHistory($schedule, 'cancelled', $before, $schedule->fresh()->toArray(), $actor, $reason);
        $this->notifyCancelled($schedule->fresh(['employee', 'provider']), $actor);

        return $schedule->fresh(['employee', 'provider', 'lead']);
    }

    public function markCompleted(DemoSchedule $schedule, ?User $actor = null): DemoSchedule
    {
        $actor ??= auth()->user();
        if (! $this->calendarService->canEditSchedule($actor, $schedule)) {
            throw new InvalidArgumentException('You do not have permission to update this demo.');
        }

        $before = $schedule->toArray();
        $schedule->update([
            'status' => DemoSchedule::STATUS_COMPLETED,
            'updated_by_user_id' => $actor?->id,
        ]);
        $this->recordHistory($schedule, 'completed', $before, $schedule->fresh()->toArray(), $actor);

        return $schedule->fresh(['employee', 'provider', 'lead']);
    }

    public function markMissed(DemoSchedule $schedule, ?User $actor = null): DemoSchedule
    {
        $actor ??= auth()->user();
        if (! $this->calendarService->canEditSchedule($actor, $schedule)) {
            throw new InvalidArgumentException('You do not have permission to update this demo.');
        }

        $before = $schedule->toArray();
        $schedule->update([
            'status' => DemoSchedule::STATUS_MISSED,
            'updated_by_user_id' => $actor?->id,
        ]);
        $this->recordHistory($schedule, 'missed', $before, $schedule->fresh()->toArray(), $actor);

        return $schedule->fresh(['employee', 'provider', 'lead']);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function recordHistory(
        DemoSchedule $schedule,
        string $action,
        ?array $before,
        ?array $after,
        ?User $actor,
        ?string $notes = null,
    ): void {
        DemoScheduleHistory::query()->create([
            'demo_schedule_id' => $schedule->id,
            'action' => $action,
            'old_values' => $before,
            'new_values' => $after,
            'performed_by_user_id' => $actor?->id,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function notifyScheduled(DemoSchedule $schedule, ?User $actor): void
    {
        $title = 'Demo Scheduled';
        $message = sprintf(
            '%s demo with %s on %s at %s.',
            $schedule->demo_provider_name ?: 'Provider',
            $schedule->firm_name ?: 'Lead',
            $schedule->demo_at?->format('d M Y') ?? '',
            $schedule->demo_at?->format('g:i A') ?? ''
        );

        if ($schedule->employee_id) {
            $userId = Employee::query()->where('employee_id', $schedule->employee_id)->value('user_id');
            if ($userId) {
                $this->notificationService->notifyUser((int) $userId, 'demo_scheduled', $title, $message, [
                    'demo_schedule_id' => $schedule->id,
                    'ca_id' => $schedule->ca_id,
                ]);
            }
        }

        $this->notificationService->notifyManagement('demo_scheduled', $title, $message, [
            'demo_schedule_id' => $schedule->id,
        ]);
    }

    private function notifyRescheduled(DemoSchedule $schedule, ?User $actor): void
    {
        $message = sprintf(
            'Demo for %s rescheduled to %s at %s.',
            $schedule->firm_name ?: 'Lead',
            $schedule->demo_at?->format('d M Y') ?? '',
            $schedule->demo_at?->format('g:i A') ?? ''
        );
        $this->notificationService->notifyManagement('demo_rescheduled', 'Demo Rescheduled', $message, [
            'demo_schedule_id' => $schedule->id,
        ]);
    }

    private function notifyCancelled(DemoSchedule $schedule, ?User $actor): void
    {
        $message = sprintf('Demo for %s on %s was cancelled.', $schedule->firm_name ?: 'Lead', $schedule->demo_at?->format('d M Y g:i A') ?? '');
        $this->notificationService->notifyManagement('demo_cancelled', 'Demo Cancelled', $message, [
            'demo_schedule_id' => $schedule->id,
        ]);
    }
}
