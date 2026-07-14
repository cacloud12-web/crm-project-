<?php

namespace App\Services\Assignment;

use App\Models\CallLog;
use App\Models\CaMaster;
use App\Models\DailyEmployeeTarget;
use App\Models\EmailLog;
use App\Models\FollowUp;
use App\Models\SmsLog;
use App\Services\Dashboard\DemoMetricsService;
use Carbon\Carbon;

class DailyEmployeeTargetProgressService
{
    public function __construct(
        private readonly DemoMetricsService $demoMetrics,
    ) {}
    private const COMPLETED_FOLLOWUP = ['Completed', 'Closed', 'Done'];

    private const EMAIL_SUCCESS = ['Sent', 'Delivered', 'Mapped', 'Queued'];

    private const SMS_SUCCESS = ['Sent', 'Delivered', 'Mapped', 'Queued', 'Pending'];

    /**
     * @return array<string, mixed>
     */
    public function achievementsForEmployee(int $employeeId, Carbon|string $date): array
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : (string) $date;

        return [
            'lead_completed' => $this->leadCompleted($employeeId, $dateString),
            'call_completed' => $this->callCompleted($employeeId, $dateString),
            'demo_completed' => $this->demoCompleted($employeeId, $dateString),
            'followup_completed' => $this->followupCompleted($employeeId, $dateString),
            'email_completed' => $this->emailCompleted($employeeId, $dateString),
            'sms_completed' => $this->smsCompleted($employeeId, $dateString),
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

    private function leadCompleted(int $employeeId, string $date): int
    {
        return CaMaster::query()
            ->countableInStatistics()
            ->where('created_by_employee_id', $employeeId)
            ->whereDate('created_at', $date)
            ->count();
    }

    private function callCompleted(int $employeeId, string $date): int
    {
        return CallLog::query()
            ->where('employee_id', $employeeId)
            ->whereDate('called_at', $date)
            ->count();
    }

    private function demoCompleted(int $employeeId, string $date): int
    {
        return $this->demoMetrics->demosScheduledCreatedOnDate($employeeId, $date);
    }

    private function followupCompleted(int $employeeId, string $date): int
    {
        return FollowUp::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::COMPLETED_FOLLOWUP)
            ->whereDate('updated_at', $date)
            ->count();
    }

    private function emailCompleted(int $employeeId, string $date): int
    {
        return EmailLog::query()
            ->where('employee_id', $employeeId)
            ->whereIn('email_status', self::EMAIL_SUCCESS)
            ->whereDate('created_at', $date)
            ->count();
    }

    private function smsCompleted(int $employeeId, string $date): int
    {
        return SmsLog::query()
            ->where('employee_id', $employeeId)
            ->whereIn('sms_status', self::SMS_SUCCESS)
            ->whereDate('created_at', $date)
            ->count();
    }
}
