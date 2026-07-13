<?php

namespace App\Services\Leads;

use App\Models\ApprovalRequest;
use App\Models\CaMaster;
use App\Models\DuplicateAttempt;
use App\Models\DuplicateAttemptLog;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\EmployeeProductivityLog;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\LeadQualityHistory;
use App\Models\SmsLog;
use App\Models\WaMessageLog;
use App\Services\Cache\CrmCacheService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmployeeProductivityService
{
    private const COMPLETED_FOLLOWUP_STATUSES = ['Completed', 'Closed', 'Done'];

    public function __construct(
        private readonly CrmCacheService $cacheService,
    ) {}

    public function employeeDailyMetrics(int $employeeId, ?Carbon $date = null): array
    {
        $date = $date ?? now();
        $metrics = $this->computeDailyMetrics($employeeId, $date);
        $rankings = $this->employeeRankings($date->toDateString());
        $rankIndex = collect($rankings['by_score'])->search(
            fn ($row) => (int) $row['employee_id'] === $employeeId,
        );
        $metrics['rank'] = $rankIndex === false ? null : $rankIndex + 1;
        $this->persistSnapshot($employeeId, $date, $metrics);

        return $metrics;
    }

    public function refreshDailySnapshot(int $employeeId, ?Carbon $date = null): EmployeeProductivityLog
    {
        $date = $date ?? now();
        $metrics = $this->computeDailyMetrics($employeeId, $date);

        return $this->persistSnapshot($employeeId, $date, $metrics);
    }

    /**
     * @return array<string, mixed>
     */
    public function managerDashboardWidgets(?Carbon $date = null): array
    {
        $date = $date ?? now();
        $dateString = $date->toDateString();
        $rankings = $this->employeeRankings($dateString);

        $byFollowup = collect($rankings['by_score'])
            ->sortByDesc('followup_completion_pct')
            ->values()
            ->take(5)
            ->all();

        $byVerified = collect($rankings['by_score'])
            ->sortByDesc('verified_leads')
            ->values()
            ->take(5)
            ->all();

        return [
            'date' => $dateString,
            'top_performers' => array_slice($rankings['by_score'], 0, 5),
            'most_duplicate_attempts' => array_slice($rankings['by_duplicates'], 0, 5),
            'most_verified_leads' => $byVerified,
            'best_followup_rate' => $byFollowup,
            'lowest_productivity' => array_slice(array_reverse($rankings['by_score']), 0, 5),
            'lowest_quality_score' => array_slice(array_reverse($rankings['by_score']), 0, 5),
            'highest_unique_leads' => array_slice($rankings['by_unique'], 0, 5),
            'duplicate_percentage' => $rankings['org_duplicate_pct'],
            'rankings' => $rankings['by_score'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function employeeReport(?string $from = null, ?string $to = null, array $filters = []): array
    {
        $fromDate = $from ?? now()->subDays(30)->toDateString();
        $toDate = $to ?? now()->toDateString();

        $employeeQuery = Employee::query()->where('status', 'Active');

        if (! empty($filters['employee_id'])) {
            $employeeQuery->where('employee_id', (int) $filters['employee_id']);
        }

        $rows = $employeeQuery
            ->orderBy('name')
            ->get()
            ->map(function (Employee $employee) use ($fromDate, $toDate, $filters) {
                return $this->employeeRangeMetrics((int) $employee->employee_id, $fromDate, $toDate, $filters);
            })
            ->values()
            ->all();

        usort($rows, fn ($a, $b) => $b['quality_score'] <=> $a['quality_score']);

        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        $topCollector = collect($rows)->sortByDesc('unique_leads')->first();
        $leastAccurate = collect($rows)->sortBy('accuracy_pct')->first();

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'filters' => $filters,
            'rows' => $rows,
            'summary' => [
                'top_collector' => $topCollector['employee_name'] ?? null,
                'top_quality_score' => $rows[0]['employee_name'] ?? null,
                'least_accurate' => $leastAccurate['employee_name'] ?? null,
                'total_unique_leads' => array_sum(array_column($rows, 'unique_leads')),
                'total_duplicate_attempts' => array_sum(array_column($rows, 'duplicate_attempts')),
            ],
        ];
    }

    public function qualityScore(
        int $verifiedLeads,
        int $followupsCompleted,
        int $uniqueLeads,
        int $duplicateAttempts,
        int $wrongNumbers,
        int $communicationFailures,
    ): int {
        $cfg = config('crm_duplicates.productivity', []);

        $positive = ($verifiedLeads * (int) ($cfg['verified_lead_points'] ?? 2))
            + ($followupsCompleted * (int) ($cfg['followup_completed_points'] ?? 1))
            + ($uniqueLeads * (int) ($cfg['unique_lead_points'] ?? 1));

        $negative = ($duplicateAttempts * (int) ($cfg['duplicate_attempt_penalty'] ?? 2))
            + ($wrongNumbers * (int) ($cfg['wrong_number_penalty'] ?? 3))
            + ($communicationFailures * (int) ($cfg['communication_failure_penalty'] ?? 1));

        return $positive - $negative;
    }

    public function duplicatePercentage(int $uniqueLeads, int $duplicateAttempts): float
    {
        $total = $uniqueLeads + $duplicateAttempts;

        return $total > 0 ? round(($duplicateAttempts / $total) * 100, 1) : 0.0;
    }

    public function accuracyPercentage(int $uniqueLeads, int $duplicateAttempts): float
    {
        return round(100 - $this->duplicatePercentage($uniqueLeads, $duplicateAttempts), 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeDailyMetrics(int $employeeId, Carbon $date): array
    {
        $dateString = $date->toDateString();

        $uniqueLeads = CaMaster::query()
            ->countableInStatistics()
            ->where('created_by_employee_id', $employeeId)
            ->whereDate('created_at', $dateString)
            ->count();

        $duplicateAttempts = DuplicateAttempt::query()
            ->where('employee_id', $employeeId)
            ->whereDate('created_at', $dateString)
            ->count();

        if ($duplicateAttempts === 0) {
            $duplicateAttempts = DuplicateAttemptLog::query()
                ->where('employee_id', $employeeId)
                ->whereDate('attempted_at', $dateString)
                ->count();
        }

        $wrongNumbers = LeadQualityHistory::query()
            ->where('employee_id', $employeeId)
            ->where('event_type', LeadQualityHistoryService::EVENT_WRONG_NUMBER)
            ->whereDate('recorded_at', $dateString)
            ->count();

        $verifiedLeads = CaMaster::query()
            ->where('verified_by', $employeeId)
            ->where('is_verified', true)
            ->whereDate('updated_at', $dateString)
            ->count();

        $followupsCompleted = FollowUp::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::COMPLETED_FOLLOWUP_STATUSES)
            ->whereDate('updated_at', $dateString)
            ->count();

        $smsFailed = $this->communicationFailuresForDay(SmsLog::query(), 'sms_status', $employeeId, $dateString);
        $whatsappFailed = $this->communicationFailuresForDay(WaMessageLog::query(), 'message_status', $employeeId, $dateString);
        $emailFailed = $this->communicationFailuresForDay(EmailLog::query(), 'email_status', $employeeId, $dateString);

        $communicationFailures = $smsFailed + $whatsappFailed + $emailFailed;
        $invalidLeads = $wrongNumbers;
        $leadsAssigned = LeadAssignmentEngine::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'Active')
            ->whereDate('assigned_date', $dateString)
            ->count();

        $target = $this->resolveDailyTarget($employeeId, $dateString);
        $score = $this->qualityScore(
            $verifiedLeads,
            $followupsCompleted,
            $uniqueLeads,
            $duplicateAttempts,
            $wrongNumbers,
            $communicationFailures,
        );

        $followupTotal = FollowUp::query()
            ->where('employee_id', $employeeId)
            ->whereDate('scheduled_date', $dateString)
            ->count();

        $followupCompletionPct = $followupTotal > 0
            ? round(($followupsCompleted / $followupTotal) * 100, 1)
            : 0.0;

        $commAttempts = $smsFailed + $whatsappFailed + $emailFailed + max(1, $uniqueLeads);
        $commSuccessPct = round(max(0, 100 - (($communicationFailures / $commAttempts) * 100)), 1);

        return [
            'employee_id' => $employeeId,
            'date' => $dateString,
            'leads_assigned' => $leadsAssigned,
            'unique_leads' => $uniqueLeads,
            'unique_leads_added' => $uniqueLeads,
            'duplicate_attempts' => $duplicateAttempts,
            'wrong_numbers' => $wrongNumbers,
            'verified_leads' => $verifiedLeads,
            'followups_completed' => $followupsCompleted,
            'sms_failed' => $smsFailed,
            'whatsapp_failed' => $whatsappFailed,
            'email_failed' => $emailFailed,
            'invalid_leads' => $invalidLeads,
            'quality_score' => $score,
            'productivity_score' => $score,
            'rank' => null,
            'todays_target' => $target,
            'remaining_target' => max(0, $target - $uniqueLeads),
            'productivity_pct' => $target > 0 ? round(($uniqueLeads / $target) * 100, 1) : 0.0,
            'duplicate_pct' => $this->duplicatePercentage($uniqueLeads, $duplicateAttempts),
            'followup_completion_pct' => $followupCompletionPct,
            'communication_success_pct' => $commSuccessPct,
            'total_leads_added' => $uniqueLeads,
            'rejected_leads' => $this->approvalCountsForEmployee($employeeId, $dateString)['rejected'],
            'approved_leads' => $this->approvalCountsForEmployee($employeeId, $dateString)['approved'],
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function persistSnapshot(int $employeeId, Carbon $date, array $metrics): EmployeeProductivityLog
    {
        $logDate = $date->toDateString();
        $values = [
            'leads_assigned' => $metrics['leads_assigned'],
            'unique_leads_added' => $metrics['unique_leads'],
            'duplicate_attempts' => $metrics['duplicate_attempts'],
            'wrong_numbers' => $metrics['wrong_numbers'],
            'verified_leads' => $metrics['verified_leads'],
            'followups_completed' => $metrics['followups_completed'],
            'sms_failed' => $metrics['sms_failed'],
            'whatsapp_failed' => $metrics['whatsapp_failed'],
            'email_failed' => $metrics['email_failed'],
            'invalid_leads' => $metrics['invalid_leads'],
            'quality_score' => $metrics['quality_score'],
            'rank' => $metrics['rank'],
        ];

        $existing = EmployeeProductivityLog::query()
            ->where('employee_id', $employeeId)
            ->whereDate('log_date', $logDate)
            ->first();

        if ($existing) {
            $existing->update($values);

            return $existing->refresh();
        }

        try {
            return EmployeeProductivityLog::query()->create(array_merge([
                'employee_id' => $employeeId,
                'log_date' => $logDate,
            ], $values));
        } catch (QueryException $exception) {
            if (! $this->isProductivityLogUniqueViolation($exception)) {
                throw $exception;
            }

            $log = EmployeeProductivityLog::query()
                ->where('employee_id', $employeeId)
                ->whereDate('log_date', $logDate)
                ->firstOrFail();
            $log->update($values);

            return $log->refresh();
        }
    }

    private function isProductivityLogUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'employee_productivity_logs')
            && str_contains($message, 'unique');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function employeeRangeMetrics(int $employeeId, string $fromDate, string $toDate, array $filters = []): array
    {
        $employee = Employee::query()->findOrFail($employeeId);

        $leadQuery = CaMaster::query()
            ->where('created_by_employee_id', $employeeId)
            ->whereDate('created_at', '>=', $fromDate)
            ->whereDate('created_at', '<=', $toDate);

        if (! empty($filters['status'])) {
            $leadQuery->where('status', $filters['status']);
        }
        if (! empty($filters['source_id'])) {
            $leadQuery->where('source_id', (int) $filters['source_id']);
        }

        $uniqueLeads = (clone $leadQuery)->count();

        $duplicateAttempts = DuplicateAttemptLog::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attempted_at', '>=', $fromDate)
            ->whereDate('attempted_at', '<=', $toDate)
            ->count();

        $wrongNumbers = LeadQualityHistory::query()
            ->where('employee_id', $employeeId)
            ->where('event_type', LeadQualityHistoryService::EVENT_WRONG_NUMBER)
            ->whereDate('recorded_at', '>=', $fromDate)
            ->whereDate('recorded_at', '<=', $toDate)
            ->count();

        $verifiedLeads = CaMaster::query()
            ->where('verified_by', $employeeId)
            ->where('is_verified', true)
            ->whereDate('updated_at', '>=', $fromDate)
            ->whereDate('updated_at', '<=', $toDate)
            ->count();

        $totalAssigned = LeadAssignmentEngine::query()
            ->where('employee_id', $employeeId)
            ->whereDate('assigned_date', '>=', $fromDate)
            ->whereDate('assigned_date', '<=', $toDate)
            ->count();

        $followupsCompleted = FollowUp::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::COMPLETED_FOLLOWUP_STATUSES)
            ->whereDate('updated_at', '>=', $fromDate)
            ->whereDate('updated_at', '<=', $toDate)
            ->count();

        $followupScheduled = FollowUp::query()
            ->where('employee_id', $employeeId)
            ->whereDate('scheduled_date', '>=', $fromDate)
            ->whereDate('scheduled_date', '<=', $toDate)
            ->count();

        $smsFailed = SmsLog::query()
            ->where('employee_id', $employeeId)
            ->whereDate('created_at', '>=', $fromDate)
            ->whereDate('created_at', '<=', $toDate)
            ->whereIn('sms_status', ['Failed', 'failed'])
            ->count();

        $whatsappFailed = WaMessageLog::query()
            ->where('employee_id', $employeeId)
            ->whereDate('created_at', '>=', $fromDate)
            ->whereDate('created_at', '<=', $toDate)
            ->whereIn('message_status', ['Failed', 'failed', 'Skipped'])
            ->count();

        $emailFailed = EmailLog::query()
            ->where('employee_id', $employeeId)
            ->whereDate('created_at', '>=', $fromDate)
            ->whereDate('created_at', '<=', $toDate)
            ->whereIn('email_status', ['Failed', 'failed', 'Bounced'])
            ->count();

        $communicationFailures = $smsFailed + $whatsappFailed + $emailFailed;
        $score = $this->qualityScore($verifiedLeads, $followupsCompleted, $uniqueLeads, $duplicateAttempts, $wrongNumbers, $communicationFailures);

        return [
            'employee_id' => $employeeId,
            'employee_name' => $employee->name,
            'total_assigned' => $totalAssigned,
            'total_completed' => $followupsCompleted,
            'unique_leads' => $uniqueLeads,
            'duplicate_leads' => 0,
            'duplicate_attempts' => $duplicateAttempts,
            'wrong_numbers' => $wrongNumbers,
            'verified_leads' => $verifiedLeads,
            'followup_completion_pct' => $followupScheduled > 0
                ? round(($followupsCompleted / $followupScheduled) * 100, 1)
                : 0.0,
            'communication_success_pct' => round(max(0, 100 - ($communicationFailures / max(1, $uniqueLeads + $communicationFailures) * 100)), 1),
            'accuracy_pct' => $this->accuracyPercentage($uniqueLeads, $duplicateAttempts),
            'duplicate_pct' => $this->duplicatePercentage($uniqueLeads, $duplicateAttempts),
            'quality_score' => $score,
            'productivity_score' => $score,
            'rank' => null,
        ];
    }

    private function communicationFailuresForDay($query, string $statusColumn, int $employeeId, string $dateString): int
    {
        return (clone $query)
            ->where('employee_id', $employeeId)
            ->whereDate('created_at', $dateString)
            ->whereIn($statusColumn, ['Failed', 'failed', 'Skipped', 'Bounced'])
            ->count();
    }

    private function resolveDailyTarget(int $employeeId, string $dateString): int
    {
        $target = DB::table('lead_assignment_engines')
            ->where('employee_id', $employeeId)
            ->where('status', 'Active')
            ->whereDate('assigned_date', '<=', $dateString)
            ->sum('target_leads');

        return max(0, (int) $target);
    }

    /**
     * @return array{approved: int, rejected: int}
     */
    private function approvalCountsForEmployee(int $employeeId, string $dateString): array
    {
        $userId = Employee::query()->where('employee_id', $employeeId)->value('user_id');

        if (! $userId) {
            return ['approved' => 0, 'rejected' => 0];
        }

        $base = ApprovalRequest::query()
            ->where('requested_by_user_id', $userId)
            ->whereDate('created_at', $dateString);

        return [
            'approved' => (clone $base)->where('status', 'approved')->count(),
            'rejected' => (clone $base)->where('status', 'rejected')->count(),
        ];
    }

    /**
     * @return array{
     *   by_score: list<array<string, mixed>>,
     *   by_unique: list<array<string, mixed>>,
     *   by_duplicates: list<array<string, mixed>>,
     *   org_duplicate_pct: float
     * }
     */
    private function employeeRankings(string $dateString): array
    {
        return $this->cacheService->rememberEmployeeRankings($dateString, function () use ($dateString) {
            $employees = Employee::query()
                ->where('status', 'Active')
                ->get(['employee_id', 'name']);

            $metricsByEmployee = $this->batchComputeRankingsMetrics(
                $employees->pluck('employee_id')->map(fn ($id) => (int) $id)->all(),
                $dateString,
            );

            /** @var Collection<int, array<string, mixed>> $rows */
            $rows = $employees->map(function (Employee $employee) use ($metricsByEmployee) {
                $employeeId = (int) $employee->employee_id;
                $metrics = $metricsByEmployee[$employeeId] ?? [
                    'unique_leads' => 0,
                    'duplicate_attempts' => 0,
                    'verified_leads' => 0,
                    'followup_completion_pct' => 0.0,
                    'quality_score' => 0,
                    'duplicate_pct' => 0.0,
                    'productivity_pct' => 0.0,
                ];

                return [
                    'employee_id' => $employeeId,
                    'employee_name' => $employee->name,
                    'unique_leads' => $metrics['unique_leads'],
                    'duplicate_attempts' => $metrics['duplicate_attempts'],
                    'verified_leads' => $metrics['verified_leads'],
                    'followup_completion_pct' => $metrics['followup_completion_pct'],
                    'quality_score' => $metrics['quality_score'],
                    'productivity_score' => $metrics['quality_score'],
                    'duplicate_pct' => $metrics['duplicate_pct'],
                    'productivity_pct' => $metrics['productivity_pct'],
                ];
            });

            $ranked = $rows->sortByDesc('quality_score')->values();
            $ranked = $ranked->map(function ($row, $index) {
                $row['rank'] = $index + 1;

                return $row;
            });

            $uniqueTotal = (int) $rows->sum('unique_leads');
            $duplicateTotal = (int) $rows->sum('duplicate_attempts');

            return [
                'by_score' => $ranked->all(),
                'by_unique' => $rows->sortByDesc('unique_leads')->values()->all(),
                'by_duplicates' => $rows->sortByDesc('duplicate_attempts')->values()->all(),
                'org_duplicate_pct' => $this->duplicatePercentage($uniqueTotal, $duplicateTotal),
            ];
        });
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, mixed>>
     */
    private function batchComputeRankingsMetrics(array $employeeIds, string $dateString): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $uniqueLeads = CaMaster::query()
            ->countableInStatistics()
            ->whereDate('created_at', $dateString)
            ->whereIn('created_by_employee_id', $employeeIds)
            ->groupBy('created_by_employee_id')
            ->selectRaw('created_by_employee_id as employee_id, COUNT(*) as total')
            ->pluck('total', 'employee_id');

        $duplicateAttempts = DuplicateAttempt::query()
            ->whereDate('created_at', $dateString)
            ->whereIn('employee_id', $employeeIds)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) as total')
            ->pluck('total', 'employee_id');

        $duplicateLogs = DuplicateAttemptLog::query()
            ->whereDate('attempted_at', $dateString)
            ->whereIn('employee_id', $employeeIds)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) as total')
            ->pluck('total', 'employee_id');

        $wrongNumbers = LeadQualityHistory::query()
            ->where('event_type', LeadQualityHistoryService::EVENT_WRONG_NUMBER)
            ->whereDate('recorded_at', $dateString)
            ->whereIn('employee_id', $employeeIds)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) as total')
            ->pluck('total', 'employee_id');

        $verifiedLeads = CaMaster::query()
            ->where('is_verified', true)
            ->whereDate('updated_at', $dateString)
            ->whereIn('verified_by', $employeeIds)
            ->groupBy('verified_by')
            ->selectRaw('verified_by as employee_id, COUNT(*) as total')
            ->pluck('total', 'employee_id');

        $followupsCompleted = FollowUp::query()
            ->whereIn('status', self::COMPLETED_FOLLOWUP_STATUSES)
            ->whereDate('updated_at', $dateString)
            ->whereIn('employee_id', $employeeIds)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) as total')
            ->pluck('total', 'employee_id');

        $followupTotals = FollowUp::query()
            ->whereDate('scheduled_date', $dateString)
            ->whereIn('employee_id', $employeeIds)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) as total')
            ->pluck('total', 'employee_id');

        $smsFailed = $this->communicationFailureCountsByEmployee(SmsLog::query(), 'sms_status', $employeeIds, $dateString);
        $whatsappFailed = $this->communicationFailureCountsByEmployee(WaMessageLog::query(), 'message_status', $employeeIds, $dateString);
        $emailFailed = $this->communicationFailureCountsByEmployee(EmailLog::query(), 'email_status', $employeeIds, $dateString);

        $targets = DB::table('lead_assignment_engines')
            ->where('status', 'Active')
            ->whereDate('assigned_date', '<=', $dateString)
            ->whereIn('employee_id', $employeeIds)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(target_leads) as target')
            ->pluck('target', 'employee_id');

        $metricsByEmployee = [];
        foreach ($employeeIds as $employeeId) {
            $unique = (int) ($uniqueLeads[$employeeId] ?? 0);
            $duplicates = (int) ($duplicateAttempts[$employeeId] ?? 0);
            if ($duplicates === 0) {
                $duplicates = (int) ($duplicateLogs[$employeeId] ?? 0);
            }
            $wrong = (int) ($wrongNumbers[$employeeId] ?? 0);
            $verified = (int) ($verifiedLeads[$employeeId] ?? 0);
            $completed = (int) ($followupsCompleted[$employeeId] ?? 0);
            $followupTotal = (int) ($followupTotals[$employeeId] ?? 0);
            $communicationFailures = (int) ($smsFailed[$employeeId] ?? 0)
                + (int) ($whatsappFailed[$employeeId] ?? 0)
                + (int) ($emailFailed[$employeeId] ?? 0);
            $target = max(0, (int) ($targets[$employeeId] ?? 0));
            $score = $this->qualityScore($verified, $completed, $unique, $duplicates, $wrong, $communicationFailures);

            $metricsByEmployee[$employeeId] = [
                'unique_leads' => $unique,
                'duplicate_attempts' => $duplicates,
                'verified_leads' => $verified,
                'followup_completion_pct' => $followupTotal > 0
                    ? round(($completed / $followupTotal) * 100, 1)
                    : 0.0,
                'quality_score' => $score,
                'duplicate_pct' => $this->duplicatePercentage($unique, $duplicates),
                'productivity_pct' => $target > 0 ? round(($unique / $target) * 100, 1) : 0.0,
            ];
        }

        return $metricsByEmployee;
    }

    /**
     * @param  list<int>  $employeeIds
     */
    private function communicationFailureCountsByEmployee($query, string $statusColumn, array $employeeIds, string $dateString): Collection
    {
        return $query
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('created_at', $dateString)
            ->whereIn($statusColumn, ['Failed', 'failed', 'Skipped', 'Bounced'])
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) as total')
            ->pluck('total', 'employee_id');
    }
}
