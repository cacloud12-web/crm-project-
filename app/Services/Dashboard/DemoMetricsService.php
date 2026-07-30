<?php

namespace App\Services\Dashboard;

use App\Models\DemoSchedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for demo schedule counts across dashboards and targets.
 *
 * Achievement rule: daily demo target uses demos scheduled (created) today, unique by demo_schedule.id.
 * Status transitions on the same record do not create additional scheduled counts.
 */
class DemoMetricsService
{
    /** @return array{0: Carbon, 1: Carbon} */
    public function dayBounds(Carbon|string $date): array
    {
        $timezone = config('app.timezone', 'UTC');
        $day = $date instanceof Carbon
            ? $date->copy()->timezone($timezone)
            : Carbon::parse($date, $timezone);

        return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
    }

    /**
     * Demos scheduled by an employee on a calendar day (created_at), excluding cancelled.
     * Used for daily target achievement.
     */
    public function demosScheduledCreatedOnDate(?int $employeeId, Carbon|string $date): int
    {
        [$start, $end] = $this->dayBounds($date);

        return (int) $this->scopedQuery($employeeId)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('status', [DemoSchedule::STATUS_CANCELLED])
            ->distinct()
            ->count('demo_schedules.id');
    }

    /**
     * Demos occurring on a calendar day (demo_at) that are still active or completed.
     */
    public function demosOccurringOnDate(?int $employeeId, Carbon|string $date): int
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : (string) $date;

        return (int) $this->scopedQuery($employeeId)
            ->whereDate('demo_at', $dateString)
            ->whereIn('status', [
                DemoSchedule::STATUS_SCHEDULED,
                DemoSchedule::STATUS_RESCHEDULED,
                DemoSchedule::STATUS_COMPLETED,
            ])
            ->distinct()
            ->count('demo_schedules.id');
    }

    public function demosCompletedOnDate(?int $employeeId, Carbon|string $date): int
    {
        [$start, $end] = $this->dayBounds($date);

        return (int) $this->scopedQuery($employeeId)
            ->where('status', DemoSchedule::STATUS_COMPLETED)
            ->whereBetween('updated_at', [$start, $end])
            ->distinct()
            ->count('demo_schedules.id');
    }

    public function demosCancelledOnDate(?int $employeeId, Carbon|string $date): int
    {
        [$start, $end] = $this->dayBounds($date);

        return (int) $this->scopedQuery($employeeId)
            ->where('status', DemoSchedule::STATUS_CANCELLED)
            ->whereBetween('updated_at', [$start, $end])
            ->distinct()
            ->count('demo_schedules.id');
    }

    public function demosRescheduledOnDate(?int $employeeId, Carbon|string $date): int
    {
        [$start, $end] = $this->dayBounds($date);

        return (int) $this->scopedQuery($employeeId)
            ->where('status', DemoSchedule::STATUS_RESCHEDULED)
            ->whereBetween('updated_at', [$start, $end])
            ->distinct()
            ->count('demo_schedules.id');
    }

    public function demosMissedCount(?int $employeeId): int
    {
        return (int) $this->scopedQuery($employeeId)
            ->where('status', DemoSchedule::STATUS_MISSED)
            ->distinct()
            ->count('demo_schedules.id');
    }

    public function demosPurchasedInRange(?int $employeeId, Carbon $from, Carbon $to): int
    {
        $fromStart = $from->copy()->startOfDay();
        $toEnd = $to->copy()->endOfDay();

        return (int) $this->scopedQuery($employeeId)
            ->whereHas('result', function (Builder $query) use ($fromStart, $toEnd) {
                $query->where('result', 'Purchased')
                    ->whereBetween('created_at', [$fromStart, $toEnd]);
            })
            ->distinct()
            ->count('demo_schedules.id');
    }

    public function demosScheduledInRange(?int $employeeId, Carbon $from, Carbon $to): int
    {
        $fromStart = $from->copy()->startOfDay();
        $toEnd = $to->copy()->endOfDay();

        return (int) $this->scopedQuery($employeeId)
            ->whereBetween('created_at', [$fromStart, $toEnd])
            ->whereNotIn('status', [DemoSchedule::STATUS_CANCELLED])
            ->distinct()
            ->count('demo_schedules.id');
    }

    public function demosCompletedInRange(?int $employeeId, Carbon $from, Carbon $to): int
    {
        $fromStart = $from->copy()->startOfDay();
        $toEnd = $to->copy()->endOfDay();

        return (int) $this->scopedQuery($employeeId)
            ->where('status', DemoSchedule::STATUS_COMPLETED)
            ->whereBetween('updated_at', [$fromStart, $toEnd])
            ->distinct()
            ->count('demo_schedules.id');
    }

    public function demosCancelledInRange(?int $employeeId, Carbon $from, Carbon $to): int
    {
        $fromStart = $from->copy()->startOfDay();
        $toEnd = $to->copy()->endOfDay();

        return (int) $this->scopedQuery($employeeId)
            ->where('status', DemoSchedule::STATUS_CANCELLED)
            ->whereBetween('updated_at', [$fromStart, $toEnd])
            ->distinct()
            ->count('demo_schedules.id');
    }

    public function demosRescheduledInRange(?int $employeeId, Carbon $from, Carbon $to): int
    {
        $fromStart = $from->copy()->startOfDay();
        $toEnd = $to->copy()->endOfDay();

        return (int) $this->scopedQuery($employeeId)
            ->where('status', DemoSchedule::STATUS_RESCHEDULED)
            ->whereBetween('updated_at', [$fromStart, $toEnd])
            ->distinct()
            ->count('demo_schedules.id');
    }

    public function demosMissedInRange(?int $employeeId, Carbon $from, Carbon $to): int
    {
        $fromStart = $from->copy()->startOfDay();
        $toEnd = $to->copy()->endOfDay();

        return (int) $this->scopedQuery($employeeId)
            ->where(function (Builder $query) use ($fromStart, $toEnd) {
                $query->where('status', DemoSchedule::STATUS_MISSED)
                    ->whereBetween('updated_at', [$fromStart, $toEnd])
                    ->orWhere(function (Builder $inner) use ($fromStart, $toEnd) {
                        $inner->where('status', DemoSchedule::STATUS_SCHEDULED)
                            ->where('demo_at', '<', now())
                            ->whereBetween('demo_at', [$fromStart, $toEnd]);
                    });
            })
            ->distinct()
            ->count('demo_schedules.id');
    }

    /**
     * @return array{
     *   demos_scheduled: int,
     *   demos_scheduled_today: int,
     *   demos_completed: int,
     *   demos_completed_today: int,
     *   demos_cancelled: int,
     *   demos_rescheduled: int,
     *   missed_demos: int,
     *   demos_purchased: int,
     *   demo_conversion_rate: float
     * }
     */
    public function aggregateForRange(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $fromStart = $from->copy()->startOfDay();
        $toEnd = $to->copy()->endOfDay();
        $today = now()->toDateString();
        [$todayStart, $todayEnd] = $this->dayBounds($today);
        $now = now();

        $cancelled = DemoSchedule::STATUS_CANCELLED;
        $completed = DemoSchedule::STATUS_COMPLETED;
        $rescheduled = DemoSchedule::STATUS_RESCHEDULED;
        $missed = DemoSchedule::STATUS_MISSED;
        $scheduled = DemoSchedule::STATUS_SCHEDULED;

        $row = $this->scopedQuery($employeeId)
            ->selectRaw(implode(', ', [
                "COUNT(DISTINCT CASE WHEN created_at BETWEEN ? AND ? AND status <> ? THEN demo_schedules.id END) as demos_scheduled",
                "COUNT(DISTINCT CASE WHEN created_at BETWEEN ? AND ? AND status <> ? THEN demo_schedules.id END) as demos_scheduled_today",
                "COUNT(DISTINCT CASE WHEN status = ? AND updated_at BETWEEN ? AND ? THEN demo_schedules.id END) as demos_completed",
                "COUNT(DISTINCT CASE WHEN status = ? AND updated_at BETWEEN ? AND ? THEN demo_schedules.id END) as demos_completed_today",
                "COUNT(DISTINCT CASE WHEN status = ? AND updated_at BETWEEN ? AND ? THEN demo_schedules.id END) as demos_cancelled",
                "COUNT(DISTINCT CASE WHEN status = ? AND updated_at BETWEEN ? AND ? THEN demo_schedules.id END) as demos_rescheduled",
                "COUNT(DISTINCT CASE WHEN (
                    (status = ? AND updated_at BETWEEN ? AND ?)
                    OR (status = ? AND demo_at < ? AND demo_at BETWEEN ? AND ?)
                ) THEN demo_schedules.id END) as missed_demos",
            ]), [
                $fromStart, $toEnd, $cancelled,
                $todayStart, $todayEnd, $cancelled,
                $completed, $fromStart, $toEnd,
                $completed, $todayStart, $todayEnd,
                $cancelled, $fromStart, $toEnd,
                $rescheduled, $fromStart, $toEnd,
                $missed, $fromStart, $toEnd,
                $scheduled, $now, $fromStart, $toEnd,
            ])
            ->first();

        $scheduledCreated = (int) ($row->demos_scheduled ?? 0);
        $completedCount = (int) ($row->demos_completed ?? 0);
        $purchased = $this->demosPurchasedInRange($employeeId, $from, $to);

        return [
            'demos_scheduled' => $scheduledCreated,
            'demos_scheduled_today' => (int) ($row->demos_scheduled_today ?? 0),
            'demos_completed' => $completedCount,
            'demos_completed_today' => (int) ($row->demos_completed_today ?? 0),
            'demos_cancelled' => (int) ($row->demos_cancelled ?? 0),
            'demos_rescheduled' => (int) ($row->demos_rescheduled ?? 0),
            'missed_demos' => (int) ($row->missed_demos ?? 0),
            'demos_purchased' => $purchased,
            'demo_conversion_rate' => $scheduledCreated > 0
                ? round(($completedCount / $scheduledCreated) * 100, 1)
                : 0.0,
        ];
    }

    private function scopedQuery(?int $employeeId): Builder
    {
        $query = DemoSchedule::query();

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }

        return $query;
    }
}
