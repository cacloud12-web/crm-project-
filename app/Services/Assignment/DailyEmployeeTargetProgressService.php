<?php

namespace App\Services\Assignment;

use App\Models\CallLog;
use App\Models\CaMaster;
use App\Models\DailyEmployeeTarget;
use App\Models\DemoSchedule;
use App\Models\EmailLog;
use App\Models\FollowUp;
use App\Models\SmsLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DailyEmployeeTargetProgressService
{
    private const COMPLETED_FOLLOWUP = ['Completed', 'Closed', 'Done'];

    private const EMAIL_SUCCESS = ['Sent', 'Delivered', 'Mapped', 'Queued'];

    private const SMS_SUCCESS = ['Sent', 'Delivered', 'Mapped', 'Queued', 'Pending'];

    /**
     * @return array<string, int>
     */
    public function achievementsForEmployee(int $employeeId, Carbon|string $date): array
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : (string) $date;
        $byEmployee = $this->achievementsForEmployeesOnDate([$employeeId], $dateString);

        return $byEmployee[$employeeId] ?? $this->emptyAchievements();
    }

    /**
     * Batch achievements for many employees on one calendar day (org dashboard / assignment cards).
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, int>>
     */
    public function achievementsForEmployeesOnDate(array $employeeIds, Carbon|string $date): array
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : (string) $date;
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($ids === []) {
            return [];
        }

        $start = Carbon::parse($dateString, config('app.timezone', 'UTC'))->startOfDay();
        $end = $start->copy()->endOfDay();

        $leads = CaMaster::query()
            ->countableInStatistics()
            ->whereIn('created_by_employee_id', $ids)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('created_by_employee_id as employee_id, COUNT(*) as aggregate')
            ->groupBy('created_by_employee_id')
            ->pluck('aggregate', 'employee_id');

        $calls = CallLog::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('called_at', [$start, $end])
            ->selectRaw('employee_id, COUNT(*) as aggregate')
            ->groupBy('employee_id')
            ->pluck('aggregate', 'employee_id');

        $demos = DemoSchedule::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('status', [DemoSchedule::STATUS_CANCELLED])
            ->selectRaw('employee_id, COUNT(DISTINCT demo_schedules.id) as aggregate')
            ->groupBy('employee_id')
            ->pluck('aggregate', 'employee_id');

        $followups = FollowUp::query()
            ->whereIn('employee_id', $ids)
            ->whereIn('status', self::COMPLETED_FOLLOWUP)
            ->whereBetween('updated_at', [$start, $end])
            ->selectRaw('employee_id, COUNT(*) as aggregate')
            ->groupBy('employee_id')
            ->pluck('aggregate', 'employee_id');

        $emails = EmailLog::query()
            ->whereIn('employee_id', $ids)
            ->whereIn('email_status', self::EMAIL_SUCCESS)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('employee_id, COUNT(*) as aggregate')
            ->groupBy('employee_id')
            ->pluck('aggregate', 'employee_id');

        $sms = SmsLog::query()
            ->whereIn('employee_id', $ids)
            ->whereIn('sms_status', self::SMS_SUCCESS)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('employee_id, COUNT(*) as aggregate')
            ->groupBy('employee_id')
            ->pluck('aggregate', 'employee_id');

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'lead_completed' => (int) ($leads[$id] ?? 0),
                'call_completed' => (int) ($calls[$id] ?? 0),
                'demo_completed' => (int) ($demos[$id] ?? 0),
                'followup_completed' => (int) ($followups[$id] ?? 0),
                'email_completed' => (int) ($emails[$id] ?? 0),
                'sms_completed' => (int) ($sms[$id] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Sum achievements for one employee across many calendar dates (YTD working days).
     * Uses 6 range/group queries instead of 6 × N day loops.
     *
     * @param  list<string>  $workingDates  Y-m-d strings
     * @return array<string, int>
     */
    public function achievementsForEmployeeOnDates(int $employeeId, array $workingDates): array
    {
        $dates = array_values(array_unique(array_filter($workingDates)));
        if ($dates === []) {
            return $this->emptyAchievements();
        }

        sort($dates);
        $from = Carbon::parse($dates[0], config('app.timezone', 'UTC'))->startOfDay();
        $to = Carbon::parse($dates[array_key_last($dates)], config('app.timezone', 'UTC'))->endOfDay();
        $dateSet = array_fill_keys($dates, true);

        return [
            'lead_completed' => $this->sumGroupedDates(
                CaMaster::query()
                    ->countableInStatistics()
                    ->where('created_by_employee_id', $employeeId)
                    ->whereBetween('created_at', [$from, $to])
                    ->selectRaw($this->dateExpr('created_at').' as activity_date, COUNT(*) as aggregate')
                    ->groupBy(DB::raw($this->dateExpr('created_at')))
                    ->pluck('aggregate', 'activity_date'),
                $dateSet,
            ),
            'call_completed' => $this->sumGroupedDates(
                CallLog::query()
                    ->where('employee_id', $employeeId)
                    ->whereBetween('called_at', [$from, $to])
                    ->selectRaw($this->dateExpr('called_at').' as activity_date, COUNT(*) as aggregate')
                    ->groupBy(DB::raw($this->dateExpr('called_at')))
                    ->pluck('aggregate', 'activity_date'),
                $dateSet,
            ),
            'demo_completed' => $this->sumGroupedDates(
                DemoSchedule::query()
                    ->where('employee_id', $employeeId)
                    ->whereBetween('created_at', [$from, $to])
                    ->whereNotIn('status', [DemoSchedule::STATUS_CANCELLED])
                    ->selectRaw($this->dateExpr('created_at').' as activity_date, COUNT(DISTINCT demo_schedules.id) as aggregate')
                    ->groupBy(DB::raw($this->dateExpr('created_at')))
                    ->pluck('aggregate', 'activity_date'),
                $dateSet,
            ),
            'followup_completed' => $this->sumGroupedDates(
                FollowUp::query()
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', self::COMPLETED_FOLLOWUP)
                    ->whereBetween('updated_at', [$from, $to])
                    ->selectRaw($this->dateExpr('updated_at').' as activity_date, COUNT(*) as aggregate')
                    ->groupBy(DB::raw($this->dateExpr('updated_at')))
                    ->pluck('aggregate', 'activity_date'),
                $dateSet,
            ),
            'email_completed' => $this->sumGroupedDates(
                EmailLog::query()
                    ->where('employee_id', $employeeId)
                    ->whereIn('email_status', self::EMAIL_SUCCESS)
                    ->whereBetween('created_at', [$from, $to])
                    ->selectRaw($this->dateExpr('created_at').' as activity_date, COUNT(*) as aggregate')
                    ->groupBy(DB::raw($this->dateExpr('created_at')))
                    ->pluck('aggregate', 'activity_date'),
                $dateSet,
            ),
            'sms_completed' => $this->sumGroupedDates(
                SmsLog::query()
                    ->where('employee_id', $employeeId)
                    ->whereIn('sms_status', self::SMS_SUCCESS)
                    ->whereBetween('created_at', [$from, $to])
                    ->selectRaw($this->dateExpr('created_at').' as activity_date, COUNT(*) as aggregate')
                    ->groupBy(DB::raw($this->dateExpr('created_at')))
                    ->pluck('aggregate', 'activity_date'),
                $dateSet,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildProgressPayload(DailyEmployeeTarget $target): array
    {
        $achievements = $this->achievementsForEmployee(
            (int) $target->employee_id,
            $target->target_date,
        );

        $metrics = $this->metricDefinitions($target, $achievements);
        $overall = $this->overallProgress($metrics);
        $status = $this->resolveStatus($overall['raw_pct'], $target->target_date);

        return [
            'metrics' => $metrics,
            'overall_pct' => $overall['display_pct'],
            'overall_raw_pct' => $overall['raw_pct'],
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'achievements' => $achievements,
        ];
    }

    /**
     * @param  array<string, int>  $achievements
     * @return list<array<string, mixed>>
     */
    private function metricDefinitions(DailyEmployeeTarget $target, array $achievements): array
    {
        $defs = [
            ['key' => 'lead', 'label' => 'Leads', 'target' => (int) $target->lead_target, 'completed' => (int) $achievements['lead_completed']],
            ['key' => 'call', 'label' => 'Calls', 'target' => (int) $target->call_target, 'completed' => (int) $achievements['call_completed']],
            ['key' => 'demo', 'label' => 'Demos', 'target' => (int) $target->demo_target, 'completed' => (int) $achievements['demo_completed']],
            ['key' => 'followup', 'label' => 'Follow-ups', 'target' => (int) $target->followup_target, 'completed' => (int) $achievements['followup_completed']],
        ];

        if ((int) $target->email_target > 0) {
            $defs[] = ['key' => 'email', 'label' => 'Emails', 'target' => (int) $target->email_target, 'completed' => (int) $achievements['email_completed']];
        }

        if ((int) $target->sms_target > 0) {
            $defs[] = ['key' => 'sms', 'label' => 'SMS', 'target' => (int) $target->sms_target, 'completed' => (int) $achievements['sms_completed']];
        }

        return array_map(function (array $row) {
            $target = max(0, (int) $row['target']);
            $completed = max(0, (int) $row['completed']);
            $pct = $target > 0 ? round(($completed / $target) * 100, 1) : 0.0;

            return array_merge($row, [
                'remaining' => max(0, $target - $completed),
                'pct' => min(100.0, $pct),
                'raw_pct' => $pct,
            ]);
        }, $defs);
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     * @return array{display_pct: float, raw_pct: float}
     */
    private function overallProgress(array $metrics): array
    {
        $enabled = array_values(array_filter($metrics, fn (array $m) => ($m['target'] ?? 0) > 0));

        if ($enabled === []) {
            return ['display_pct' => 0.0, 'raw_pct' => 0.0];
        }

        $assigned = array_sum(array_column($enabled, 'target'));
        $completed = array_sum(array_column($enabled, 'completed'));
        $raw = $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 0.0;

        return [
            'display_pct' => min(100.0, $raw),
            'raw_pct' => $raw,
        ];
    }

    private function resolveStatus(float $rawPct, Carbon|string $targetDate): string
    {
        $date = $targetDate instanceof Carbon ? $targetDate : Carbon::parse($targetDate);
        $isPast = $date->toDateString() < now()->toDateString();

        if ($rawPct <= 0) {
            return $isPast ? 'missed' : 'not_started';
        }

        if ($rawPct > 100) {
            return 'exceeded';
        }

        if ($rawPct >= 100) {
            return 'completed';
        }

        return $isPast ? 'missed' : 'in_progress';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'not_started' => 'Not Started',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'exceeded' => 'Exceeded',
            'missed' => 'Missed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /**
     * @return array<string, int>
     */
    private function emptyAchievements(): array
    {
        return [
            'lead_completed' => 0,
            'call_completed' => 0,
            'demo_completed' => 0,
            'followup_completed' => 0,
            'email_completed' => 0,
            'sms_completed' => 0,
        ];
    }

    private function dateExpr(string $column): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => 'DATE('.$column.')',
            'sqlite' => 'date('.$column.')',
            default => 'DATE('.$column.')',
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<string, mixed>  $byDate
     * @param  array<string, bool>  $dateSet
     */
    private function sumGroupedDates($byDate, array $dateSet): int
    {
        $total = 0;
        foreach ($byDate as $date => $count) {
            $key = substr((string) $date, 0, 10);
            if (isset($dateSet[$key])) {
                $total += (int) $count;
            }
        }

        return $total;
    }
}
