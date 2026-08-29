<?php

namespace App\Services\Reports;

use App\Models\AssignmentHistory;
use App\Models\CaMaster;
use App\Models\DailyEmployeeTarget;
use App\Models\DemoSchedule;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\EmployeeCalendarDay;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\SmsCampaign;
use App\Models\SmsLog;
use App\Models\WaMessageLog;
use App\Models\WhatsAppCampaign;
use App\Models\YearlyEmployeeTarget;
use App\Services\Cache\CrmCacheService;
use App\Services\Dashboard\DemoMetricsService;
use App\Services\Leads\EmployeeProductivityService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Support\Database\SqlAggregate;
use App\Support\Database\SqlDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReportsService
{
    public function __construct(
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly CrmCacheService $cacheService,
        private readonly EmployeeProductivityService $employeeProductivity,
        private readonly DemoMetricsService $demoMetricsService,
    ) {}

    public function summary(array $params = []): array
    {
        $filters = $this->parseFilters($params);
        $filterMeta = $this->filterMeta($filters);
        $scopeKey = $this->employeeDataScope->cacheScopeKey();

        return $this->cacheService->rememberReportSummary($scopeKey, $filterMeta, function () use ($filterMeta) {
            return [
                'filters' => $filterMeta,
                'reports' => collect(config('reports.reports', []))
                    ->map(function (array $meta, string $slug) use ($filterMeta) {
                        $report = $this->report($slug, $filterMeta);

                        return [
                            'slug' => $slug,
                            'label' => $meta['label'],
                            'description' => $meta['description'],
                            'card' => $meta['card'],
                            'generated_at' => now()->toIso8601String(),
                            'row_count' => count($report['rows'] ?? []),
                            'summary' => $report['summary'] ?? [],
                        ];
                    })
                    ->values()
                    ->all(),
            ];
        });
    }

    public function analytics(array $params = []): array
    {
        $filters = $this->parseFilters($params);
        $conversion = $this->leadConversion($filters);
        $employees = $this->employeePerformance($filters);
        $monthly = $this->monthlyTrends($filters);
        $city = $this->cityAnalysis($filters);

        return [
            'filters' => $this->filterMeta($filters),
            'charts' => [
                'daily_calls' => $this->dailyCallsSeries($filters),
                'demo_ratio' => $this->demoRatioSeries($filters),
                'conversion' => $this->conversionSeries($filters),
                'city_performance' => $this->chartFromRows($city['rows'], 'city', 'total_leads'),
                'lead_source' => $this->sourceBreakdownSeries($filters),
                'target_achievement' => $this->targetAchievementSeries($employees['rows']),
            ],
            'conversion_summary' => $conversion['summary'],
            'monthly_trends' => $monthly,
        ];
    }

    public function dashboardInsights(array $params = []): array
    {
        $filters = $this->parseFilters(array_merge($params, ['months' => 6]));
        $filterMeta = $this->filterMeta($filters);
        $scopeKey = $this->employeeDataScope->cacheScopeKey();

        return $this->cacheService->rememberDashboardInsights($scopeKey, $filterMeta, function () use ($filters) {
            $conversion = $this->leadConversion($filters);
            $employees = $this->employeePerformance($filters);
            $monthly = $this->monthlyTrends($filters);
            $campaigns = $this->campaignAnalytics($filters);

            return [
                'conversion_summary' => $conversion['summary'],
                'status_breakdown' => $conversion['breakdown'],
                'monthly_trends' => $monthly['rows'],
                'employee_performance' => array_slice($employees['rows'], 0, 12),
                'city_breakdown' => $this->cityAnalysis($filters)['rows'],
                'source_breakdown' => $this->sourceBreakdownRows($filters),
                'campaign_summary' => $campaigns['summary'],
                'campaign_channels' => $campaigns['breakdown'] ?? [],
            ];
        });
    }

    public function report(string $slug, array $params = []): array
    {
        $filters = $this->parseFilters($params);

        return match ($slug) {
            'lead_conversion' => $this->leadConversion($filters),
            'employee_performance' => $this->employeePerformance($filters),
            'followup_performance' => $this->followupPerformance($filters),
            'assignment_statistics' => $this->assignmentStatistics($filters),
            'campaign_analytics' => $this->campaignAnalytics($filters),
            'monthly_trends' => $this->monthlyTrends($filters),
            'city_analysis' => $this->cityAnalysis($filters),
            'lost_lead_analysis' => $this->lostLeadAnalysis($filters),
            'duplicate_productivity' => $this->duplicateProductivityReport($filters),
            default => throw new InvalidArgumentException('Unknown report: '.$slug),
        };
    }

    public function exportData(string $slug, array $params = []): array
    {
        $report = $this->report($slug, $params);

        return [
            'filename' => $slug.'-'.now()->format('Y-m-d').'.csv',
            'columns' => $report['columns'] ?? [],
            'rows' => $report['rows'] ?? [],
        ];
    }

    public function exportSummary(array $params = []): array
    {
        $filters = $this->parseFilters($params);
        $rows = [];
        $conversion = $this->leadConversion($filters)['summary'];
        $campaigns = $this->campaignAnalytics($filters)['summary'];

        foreach ($conversion as $key => $value) {
            $rows[] = ['section' => 'Lead Conversion', 'metric' => $key, 'value' => (string) $value];
        }

        foreach ($campaigns as $key => $value) {
            $rows[] = ['section' => 'Campaign Analytics', 'metric' => $key, 'value' => (string) $value];
        }

        foreach ($this->employeePerformance($filters)['rows'] as $row) {
            $rows[] = [
                'section' => 'Employee Performance',
                'metric' => $row['employee_name'],
                'value' => $row['demos_scheduled'].' demos · '.$row['achievement_pct'].'% target',
            ];
        }

        return [
            'filename' => 'enterprise-reports-summary-'.now()->format('Y-m-d').'.csv',
            'columns' => [
                'section' => 'Section',
                'metric' => 'Metric',
                'value' => 'Value',
            ],
            'rows' => $rows,
        ];
    }

    private function duplicateProductivityReport(array $filters): array
    {
        $report = $this->employeeProductivity->employeeReport(
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            $filters,
        );

        return [
            'slug' => 'duplicate_productivity',
            'label' => config('reports.reports.duplicate_productivity.label'),
            'summary' => $report['summary'],
            'columns' => [
                'rank' => 'Rank',
                'employee_name' => 'Employee',
                'total_assigned' => 'Total Assigned',
                'total_completed' => 'Follow-ups Completed',
                'unique_leads' => 'Unique Leads',
                'duplicate_attempts' => 'Duplicate Attempts',
                'wrong_numbers' => 'Wrong Numbers',
                'verified_leads' => 'Verified Leads',
                'followup_completion_pct' => 'Follow-up %',
                'communication_success_pct' => 'Communication Success %',
                'quality_score' => 'Quality Score',
            ],
            'rows' => $report['rows'],
        ];
    }

    private function leadConversion(array $filters): array
    {
        $lost = $this->quotedList(config('reports.lost_statuses', []));
        $pipeline = $this->quotedList(config('reports.pipeline_statuses', []));

        $summaryRow = $this->scopedCaMasterQuery($filters)
            ->when($filters['from'], fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('created_at', '<=', $filters['to']))
            ->when($filters['city_id'], fn ($q) => $q->where('city_id', $filters['city_id']))
            ->selectRaw('COUNT(*) as total_leads')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Hot'").' as hot_leads')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Warm'").' as warm_leads')
            ->selectRaw(SqlAggregate::countFilter('*', 'status IN ('.$pipeline.')').' as pipeline_leads')
            ->selectRaw(SqlAggregate::countFilter('*', 'software_purchased = true').' as won_leads')
            ->selectRaw(SqlAggregate::countFilter('*', 'status IN ('.$lost.')').' as lost_leads')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Demo Scheduled'").' as demo_scheduled')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'New'").' as new_leads')
            ->first();

        $total = (int) ($summaryRow->total_leads ?? 0);
        $wonCount = (int) ($summaryRow->won_leads ?? 0);
        $demoCount = (int) ($summaryRow->demo_scheduled ?? 0);

        $statusRows = $this->scopedCaMasterQuery($filters)
            ->when($filters['from'], fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('created_at', '<=', $filters['to']))
            ->when($filters['city_id'], fn ($q) => $q->where('city_id', $filters['city_id']))
            ->selectRaw('status')
            ->selectRaw('COUNT(*) as lead_count')
            ->groupBy('status')
            ->orderByDesc('lead_count')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'lead_count' => (int) $row->lead_count,
                'share_pct' => $total ? round(((int) $row->lead_count / $total) * 100, 1) : 0,
            ])
            ->all();

        $newLeadRows = $this->scopedCaMasterQuery($filters)
            ->when($filters['from'], fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('created_at', '<=', $filters['to']))
            ->when($filters['city_id'], fn ($q) => $q->where('city_id', $filters['city_id']))
            ->selectRaw('DATE(created_at) as report_date')
            ->selectRaw('COUNT(*) as new_leads')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('report_date')
            ->get()
            ->keyBy('report_date');

        $convertedRows = $this->scopedCaMasterQuery($filters)
            ->where('software_purchased', true)
            ->whereNotNull('purchase_date')
            ->when($filters['from'], fn ($q) => $q->where('purchase_date', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('purchase_date', '<=', $filters['to']))
            ->when($filters['city_id'], fn ($q) => $q->where('city_id', $filters['city_id']))
            ->selectRaw(SqlDate::dateCast('purchase_date').' as report_date')
            ->selectRaw('COUNT(*) as converted_leads')
            ->groupBy(DB::raw(SqlDate::dateCast('purchase_date')))
            ->orderBy('report_date')
            ->get()
            ->keyBy('report_date');

        $allDates = $newLeadRows->keys()->merge($convertedRows->keys())->unique()->sort()->values();

        $dailyRows = $allDates->map(function ($date) use ($newLeadRows, $convertedRows) {
            return [
                'report_date' => $date,
                'new_leads' => (int) ($newLeadRows->get($date)?->new_leads ?? 0),
                'converted_leads' => (int) ($convertedRows->get($date)?->converted_leads ?? 0),
            ];
        })->all();

        return [
            'slug' => 'lead_conversion',
            'label' => config('reports.reports.lead_conversion.label'),
            'summary' => [
                'total_leads' => $total,
                'hot_leads' => (int) ($summaryRow->hot_leads ?? 0),
                'warm_leads' => (int) ($summaryRow->warm_leads ?? 0),
                'pipeline_leads' => (int) ($summaryRow->pipeline_leads ?? 0),
                'won_leads' => $wonCount,
                'lost_leads' => (int) ($summaryRow->lost_leads ?? 0),
                'demo_scheduled' => $demoCount,
                'conversion_rate_pct' => $total ? round(($wonCount / $total) * 100, 1) : 0,
                'demo_ratio_pct' => $total ? round(($demoCount / $total) * 100, 1) : 0,
            ],
            'columns' => [
                'report_date' => 'Date',
                'new_leads' => 'New Leads',
                'converted_leads' => 'Converted',
            ],
            'rows' => $dailyRows,
            'breakdown' => $statusRows,
        ];
    }

    private function employeePerformance(array $filters): array
    {
        $from = $filters['from'];
        $to = $filters['to'];
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $employees = Employee::query()
            ->leftJoin('cities', 'cities.city_id', '=', 'employees.city_id')
            ->when($filters['employee_id'], fn ($q) => $q->where('employees.employee_id', $filters['employee_id']))
            ->where('employees.status', 'Active')
            ->orderBy('employees.name')
            ->get([
                'employees.employee_id',
                'employees.name as employee_name',
                'employees.role',
                DB::raw("COALESCE(cities.city_name, '—') as city"),
            ]);

        $employeeIds = $employees->pluck('employee_id')->map(fn ($id) => (int) $id)->all();

        if ($employeeIds === []) {
            return [
                'slug' => 'employee_performance',
                'label' => config('reports.reports.employee_performance.label'),
                'summary' => [
                    'active_employees' => 0,
                    'total_assigned_leads' => 0,
                    'avg_achievement_pct' => 0,
                    'total_overdue_followups' => 0,
                ],
                'columns' => $this->employeePerformanceColumns(),
                'rows' => [],
            ];
        }

        $assignedByEmployee = LeadAssignmentEngine::query()
            ->join('ca_masters', 'ca_masters.ca_id', '=', 'lead_assignment_engines.ca_id')
            ->whereIn('lead_assignment_engines.employee_id', $employeeIds)
            ->where('lead_assignment_engines.status', 'Active')
            ->where(function ($q) {
                $q->whereNull('ca_masters.mobile_no_type')
                    ->orWhere('ca_masters.mobile_no_type', 'mobile');
            })
            ->whereDate('lead_assignment_engines.assigned_date', '>=', $fromDate)
            ->whereDate('lead_assignment_engines.assigned_date', '<=', $toDate)
            ->selectRaw('lead_assignment_engines.employee_id')
            ->selectRaw('COUNT(DISTINCT lead_assignment_engines.ca_id) as assigned_leads')
            ->groupBy('lead_assignment_engines.employee_id')
            ->pluck('assigned_leads', 'employee_id');

        $demosByEmployee = DemoSchedule::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', [DemoSchedule::STATUS_CANCELLED])
            ->selectRaw('employee_id')
            ->selectRaw('COUNT(DISTINCT demo_schedules.id) as demos_scheduled')
            ->groupBy('employee_id')
            ->pluck('demos_scheduled', 'employee_id');

        $completedFollowupsByEmployee = FollowUp::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', config('reports.completed_followup_statuses', []))
            ->whereBetween('updated_at', [$from, $to])
            ->selectRaw('employee_id')
            ->selectRaw('COUNT(*) as completed_followups')
            ->groupBy('employee_id')
            ->pluck('completed_followups', 'employee_id');

        $overdueByEmployee = FollowUp::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', config('reports.open_followup_statuses', []))
            ->whereDate('scheduled_date', '>=', $fromDate)
            ->whereDate('scheduled_date', '<=', $toDate)
            ->whereDate('scheduled_date', '<', now()->toDateString())
            ->selectRaw('employee_id')
            ->selectRaw('COUNT(*) as overdue_followups')
            ->groupBy('employee_id')
            ->pluck('overdue_followups', 'employee_id');

        $demoTargetsByEmployee = $this->demoTargetsInRangeForEmployees($employeeIds, $fromDate, $toDate);

        $rows = $employees->map(function ($employee) use (
            $assignedByEmployee,
            $demosByEmployee,
            $demoTargetsByEmployee,
            $completedFollowupsByEmployee,
            $overdueByEmployee,
        ) {
            $employeeId = (int) $employee->employee_id;
            $demosScheduled = (int) ($demosByEmployee[$employeeId] ?? 0);
            $demoTarget = (int) ($demoTargetsByEmployee[$employeeId] ?? 0);

            return [
                'employee_id' => $employeeId,
                'employee_name' => $employee->employee_name,
                'role' => $employee->role,
                'city' => $employee->city,
                'assigned_leads' => (int) ($assignedByEmployee[$employeeId] ?? 0),
                'target_leads' => $demoTarget,
                'achieved_leads' => $demosScheduled,
                'demos_scheduled' => $demosScheduled,
                'demo_target' => $demoTarget,
                'achievement_pct' => $demoTarget > 0
                    ? round(($demosScheduled / $demoTarget) * 100, 1)
                    : ($demosScheduled > 0 ? 100.0 : 0.0),
                'total_followups' => (int) ($completedFollowupsByEmployee[$employeeId] ?? 0),
                'completed_followups' => (int) ($completedFollowupsByEmployee[$employeeId] ?? 0),
                'overdue_followups' => (int) ($overdueByEmployee[$employeeId] ?? 0),
                'demo_followups' => $demosScheduled,
            ];
        })
            ->sortByDesc('demos_scheduled')
            ->values()
            ->all();

        $summary = [
            'active_employees' => count($rows),
            'total_assigned_leads' => array_sum(array_column($rows, 'assigned_leads')),
            'total_demos_scheduled' => array_sum(array_column($rows, 'demos_scheduled')),
            'total_demo_target' => array_sum(array_column($rows, 'demo_target')),
            'avg_achievement_pct' => count($rows)
                ? round(array_sum(array_column($rows, 'achievement_pct')) / count($rows), 1)
                : 0,
            'total_overdue_followups' => array_sum(array_column($rows, 'overdue_followups')),
        ];

        return [
            'slug' => 'employee_performance',
            'label' => config('reports.reports.employee_performance.label'),
            'summary' => $summary,
            'columns' => $this->employeePerformanceColumns(),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function employeePerformanceColumns(): array
    {
        return [
            'employee_name' => 'Employee',
            'city' => 'City',
            'demos_scheduled' => 'Demos Scheduled',
            'demo_target' => 'Demo Target',
            'achievement_pct' => 'Achievement %',
            'assigned_leads' => 'Leads Assigned',
            'completed_followups' => 'Completed Follow-ups',
            'overdue_followups' => 'Overdue',
        ];
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, int>
     */
    private function demoTargetsInRangeForEmployees(array $employeeIds, string $fromDate, string $toDate): array
    {
        $totals = array_fill_keys($employeeIds, 0);

        $calendarDays = EmployeeCalendarDay::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('day_type', EmployeeCalendarDay::TYPE_WORKING)
            ->whereDate('calendar_date', '>=', $fromDate)
            ->whereDate('calendar_date', '<=', $toDate)
            ->get(['employee_id', 'calendar_date', 'demo_target']);

        $overrides = DailyEmployeeTarget::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('target_date', '>=', $fromDate)
            ->whereDate('target_date', '<=', $toDate)
            ->get(['employee_id', 'target_date', 'demo_target']);

        $overrideMap = [];
        foreach ($overrides as $override) {
            $dateString = $override->target_date instanceof Carbon
                ? $override->target_date->toDateString()
                : (string) $override->target_date;
            $overrideMap[(int) $override->employee_id.'|'.$dateString] = (int) $override->demo_target;
        }

        $employeesWithCalendar = [];
        foreach ($calendarDays as $day) {
            $employeeId = (int) $day->employee_id;
            $employeesWithCalendar[$employeeId] = true;
            $dateString = $day->calendar_date instanceof Carbon
                ? $day->calendar_date->toDateString()
                : (string) $day->calendar_date;
            $key = $employeeId.'|'.$dateString;
            $totals[$employeeId] += (int) ($overrideMap[$key] ?? $day->demo_target);
        }

        foreach ($employeeIds as $employeeId) {
            if (! empty($employeesWithCalendar[$employeeId])) {
                continue;
            }

            $totals[$employeeId] = (int) DailyEmployeeTarget::query()
                ->where('employee_id', $employeeId)
                ->whereDate('target_date', '>=', $fromDate)
                ->whereDate('target_date', '<=', $toDate)
                ->sum('demo_target');
        }

        $yearlyTargets = YearlyEmployeeTarget::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('target_year', [
                (int) Carbon::parse($fromDate)->year,
                (int) Carbon::parse($toDate)->year,
            ])
            ->get()
            ->groupBy('employee_id');

        foreach ($employeeIds as $employeeId) {
            if (($totals[$employeeId] ?? 0) > 0) {
                continue;
            }

            $yearly = $this->resolveYearlyDemoTargetForRange($yearlyTargets->get($employeeId), $fromDate, $toDate);
            if (! $yearly) {
                continue;
            }

            $workingDays = ! empty($employeesWithCalendar[$employeeId])
                ? $calendarDays->where('employee_id', $employeeId)->count()
                : $this->countStandardWorkingDaysInRange($fromDate, $toDate);

            if ($workingDays > 0) {
                $totals[$employeeId] = (int) $yearly->demo_target * $workingDays;
            }
        }

        return $totals;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, YearlyEmployeeTarget>|null  $targets
     */
    private function resolveYearlyDemoTargetForRange($targets, string $fromDate, string $toDate): ?YearlyEmployeeTarget
    {
        if (! $targets || $targets->isEmpty()) {
            return null;
        }

        $fromYear = (int) Carbon::parse($fromDate)->year;
        $toYear = (int) Carbon::parse($toDate)->year;

        return $targets->first(function (YearlyEmployeeTarget $target) use ($fromYear, $toYear) {
            $year = (int) $target->target_year;

            return $year >= $fromYear && $year <= $toYear && (int) $target->demo_target > 0;
        });
    }

    private function countStandardWorkingDaysInRange(string $fromDate, string $toDate): int
    {
        $count = 0;
        foreach (\Carbon\CarbonPeriod::create($fromDate, $toDate) as $date) {
            if (! $date->isSunday()) {
                $count++;
            }
        }

        return $count;
    }

    private function followupPerformance(array $filters): array
    {
        $open = $this->quotedList(config('reports.open_followup_statuses', []));
        $completed = $this->quotedList(config('reports.completed_followup_statuses', []));

        $demoSummary = $this->demoMetricsService->aggregateForRange(
            $filters['employee_id'],
            $filters['from'],
            $filters['to'],
        );

        $rows = $this->scopedFollowUpQuery($filters)
            ->when($filters['from'], fn ($q) => $q->where('scheduled_date', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('scheduled_date', '<=', $filters['to']))
            ->when($filters['employee_id'], fn ($q) => $q->where('employee_id', $filters['employee_id']))
            ->selectRaw('followup_type')
            ->selectRaw('COUNT(*) as total_followups')
            ->selectRaw(SqlAggregate::countFilter('*', 'status IN ('.$completed.')').' as completed')
            ->selectRaw(SqlAggregate::countFilter('*', 'status IN ('.$open.')').' as open_count')
            ->selectRaw(SqlAggregate::countFilter('*', 'status IN ('.$open.') AND scheduled_date < CURRENT_DATE').' as overdue')
            ->selectRaw(SqlAggregate::countFilter('*', "followup_type ILIKE '%Demo%'").' as demo_related')
            ->groupBy('followup_type')
            ->orderByDesc('total_followups')
            ->get()
            ->map(fn ($row) => [
                'followup_type' => $row->followup_type,
                'total_followups' => (int) $row->total_followups,
                'completed' => (int) $row->completed,
                'open_count' => (int) $row->open_count,
                'overdue' => (int) $row->overdue,
                'demo_related' => (int) $row->demo_related,
                'completion_rate_pct' => (int) $row->total_followups
                    ? round(((int) $row->completed / (int) $row->total_followups) * 100, 1)
                    : 0,
            ])
            ->all();

        $demoScheduleRows = $this->demoScheduleReportRows($demoSummary);
        $rows = array_merge($demoScheduleRows, $rows);

        $summaryRow = $this->scopedFollowUpQuery($filters)
            ->when($filters['from'], fn ($q) => $q->where('scheduled_date', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('scheduled_date', '<=', $filters['to']))
            ->when($filters['employee_id'], fn ($q) => $q->where('employee_id', $filters['employee_id']))
            ->selectRaw('COUNT(*) as total_followups')
            ->selectRaw(SqlAggregate::countFilter('*', 'status IN ('.$completed.')').' as completed')
            ->selectRaw(SqlAggregate::countFilter('*', 'status IN ('.$open.') AND scheduled_date < CURRENT_DATE').' as overdue')
            ->selectRaw(SqlAggregate::countFilter('*', "followup_type ILIKE '%Demo%'").' as demo_followups')
            ->first();

        return [
            'slug' => 'followup_performance',
            'label' => config('reports.reports.followup_performance.label'),
            'summary' => [
                'total_followups' => (int) ($summaryRow->total_followups ?? 0),
                'completed' => (int) ($summaryRow->completed ?? 0),
                'overdue' => (int) ($summaryRow->overdue ?? 0),
                'demo_followups' => (int) ($summaryRow->demo_followups ?? 0),
                'demos_scheduled' => (int) ($demoSummary['demos_scheduled'] ?? 0),
                'demos_completed' => (int) ($demoSummary['demos_completed'] ?? 0),
                'demos_rescheduled' => (int) ($demoSummary['demos_rescheduled'] ?? 0),
                'demos_cancelled' => (int) ($demoSummary['demos_cancelled'] ?? 0),
                'missed_demos' => (int) ($demoSummary['missed_demos'] ?? 0),
                'demo_conversion_rate' => (float) ($demoSummary['demo_conversion_rate'] ?? 0),
            ],
            'demo_schedule_summary' => $demoSummary,
            'demo_schedule_note' => 'Demo Schedules row uses demo_schedules (same as dashboard). Follow-up rows track follow-up tasks; completed demos move from Demo Scheduled to Demo Completed type.',
            'columns' => [
                'followup_type' => 'Type',
                'total_followups' => 'Total',
                'completed' => 'Completed',
                'open_count' => 'Open',
                'overdue' => 'Overdue',
                'demo_related' => 'Demo Related',
                'completion_rate_pct' => 'Completion %',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $demoSummary
     * @return list<array<string, mixed>>
     */
    private function demoScheduleReportRows(array $demoSummary): array
    {
        $scheduled = (int) ($demoSummary['demos_scheduled'] ?? 0);
        $completed = (int) ($demoSummary['demos_completed'] ?? 0);

        return [
            [
                'followup_type' => 'Demo Schedules (dashboard)',
                'total_followups' => $scheduled,
                'completed' => $completed,
                'open_count' => max(0, $scheduled - $completed),
                'overdue' => (int) ($demoSummary['missed_demos'] ?? 0),
                'demo_related' => $scheduled,
                'completion_rate_pct' => (float) ($demoSummary['demo_conversion_rate'] ?? 0),
                'is_demo_schedule_row' => true,
            ],
        ];
    }

    private function assignmentStatistics(array $filters): array
    {
        $rows = AssignmentHistory::query()
            ->when($filters['from'], fn ($q) => $q->where('assigned_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('assigned_at', '<=', $filters['to']))
            ->when($filters['employee_id'], fn ($q) => $q->where('new_employee_id', $filters['employee_id']))
            ->selectRaw('assignment_type')
            ->selectRaw('COUNT(*) as assignment_count')
            ->selectRaw('COUNT(DISTINCT ca_id) as unique_leads')
            ->selectRaw('COUNT(DISTINCT new_employee_id) as executives_involved')
            ->groupBy('assignment_type')
            ->orderByDesc('assignment_count')
            ->get()
            ->map(fn ($row) => [
                'assignment_type' => $row->assignment_type,
                'assignment_count' => (int) $row->assignment_count,
                'unique_leads' => (int) $row->unique_leads,
                'executives_involved' => (int) $row->executives_involved,
            ])
            ->all();

        $dailyRows = AssignmentHistory::query()
            ->when($filters['from'], fn ($q) => $q->where('assigned_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('assigned_at', '<=', $filters['to']))
            ->when($filters['employee_id'], fn ($q) => $q->where('new_employee_id', $filters['employee_id']))
            ->selectRaw('DATE(assigned_at) as report_date')
            ->selectRaw('COUNT(*) as assignments')
            ->selectRaw('COUNT(DISTINCT ca_id) as unique_leads')
            ->groupBy(DB::raw('DATE(assigned_at)'))
            ->orderBy('report_date')
            ->get()
            ->map(fn ($row) => [
                'report_date' => $row->report_date,
                'assignments' => (int) $row->assignments,
                'unique_leads' => (int) $row->unique_leads,
            ])
            ->all();

        $activeQuery = LeadAssignmentEngine::query()->where('status', 'Active');
        if (! empty($filters['employee_id'])) {
            $this->employeeDataScope->scopeLeadAssignmentQuery($activeQuery, (int) $filters['employee_id']);
        }
        $activeAssignments = $activeQuery->count();
        $reassignments = AssignmentHistory::query()
            ->when($filters['from'], fn ($q) => $q->where('assigned_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('assigned_at', '<=', $filters['to']))
            ->when($filters['employee_id'], fn ($q) => $q->where('new_employee_id', $filters['employee_id']))
            ->whereNotNull('previous_employee_id')
            ->count();

        return [
            'slug' => 'assignment_statistics',
            'label' => config('reports.reports.assignment_statistics.label'),
            'summary' => [
                'active_assignments' => $activeAssignments,
                'total_assignments' => array_sum(array_column($rows, 'assignment_count')),
                'reassignments' => $reassignments,
                'assignment_types' => count($rows),
            ],
            'columns' => [
                'report_date' => 'Date',
                'assignments' => 'Assignments',
                'unique_leads' => 'Unique Leads',
            ],
            'rows' => $dailyRows,
            'breakdown' => $rows,
        ];
    }

    private function campaignAnalytics(array $filters): array
    {
        $from = $filters['from'];
        $to = $filters['to'];

        $whatsapp = $this->channelCampaignStats(WhatsAppCampaign::query(), WaMessageLog::query(), 'message_status', $from, $to);
        $email = $this->channelCampaignStats(EmailCampaign::query(), EmailLog::query(), 'email_status', $from, $to);
        $sms = $this->channelCampaignStats(SmsCampaign::query(), SmsLog::query(), 'sms_status', $from, $to);

        $rows = [
            array_merge(['channel' => 'WhatsApp'], $whatsapp),
            array_merge(['channel' => 'Email'], $email),
            array_merge(['channel' => 'SMS'], $sms),
        ];

        $campaignRows = collect()
            ->merge(
                WhatsAppCampaign::query()
                    ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                    ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                    ->get(['id', 'campaign_name', 'status', 'total_messages', 'delivered_count', 'failed_count'])
                    ->map(fn ($c) => [
                        'channel' => 'WhatsApp',
                        'campaign_name' => $c->campaign_name,
                        'status' => $c->status,
                        'total_messages' => (int) $c->total_messages,
                        'delivered_count' => (int) $c->delivered_count,
                        'failed_count' => (int) $c->failed_count,
                    ])
            )
            ->merge(
                EmailCampaign::query()
                    ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                    ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                    ->get(['id', 'campaign_name', 'status', 'total_emails', 'delivered_count', 'failed_count'])
                    ->map(fn ($c) => [
                        'channel' => 'Email',
                        'campaign_name' => $c->campaign_name,
                        'status' => $c->status,
                        'total_messages' => (int) $c->total_emails,
                        'delivered_count' => (int) $c->delivered_count,
                        'failed_count' => (int) $c->failed_count,
                    ])
            )
            ->merge(
                SmsCampaign::query()
                    ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                    ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                    ->get(['id', 'campaign_name', 'status', 'total_sms', 'delivered_count', 'failed_count'])
                    ->map(fn ($c) => [
                        'channel' => 'SMS',
                        'campaign_name' => $c->campaign_name,
                        'status' => $c->status,
                        'total_messages' => (int) $c->total_sms,
                        'delivered_count' => (int) $c->delivered_count,
                        'failed_count' => (int) $c->failed_count,
                    ])
            )
            ->values()
            ->all();

        $totalSent = array_sum(array_column($rows, 'messages_total'));
        $totalDelivered = array_sum(array_column($rows, 'delivered'));

        return [
            'slug' => 'campaign_analytics',
            'label' => config('reports.reports.campaign_analytics.label'),
            'summary' => [
                'campaigns_total' => array_sum(array_column($rows, 'campaigns_total')),
                'messages_total' => $totalSent,
                'delivered_total' => $totalDelivered,
                'failed_total' => array_sum(array_column($rows, 'failed')),
                'delivery_rate_pct' => $totalSent ? round(($totalDelivered / $totalSent) * 100, 1) : 0,
            ],
            'columns' => [
                'channel' => 'Channel',
                'campaign_name' => 'Campaign',
                'status' => 'Status',
                'total_messages' => 'Sent',
                'delivered_count' => 'Delivered',
                'failed_count' => 'Failed',
            ],
            'rows' => $campaignRows,
            'breakdown' => $rows,
        ];
    }

    private function monthlyTrends(array $filters): array
    {
        $months = $filters['months'];
        $from = now()->subMonths($months - 1)->startOfMonth();
        $lost = $this->quotedList(config('reports.lost_statuses', []));

        $rows = $this->scopedCaMasterQuery($filters)
            ->where('created_at', '>=', $from)
            ->selectRaw(SqlDate::monthLabel('created_at'))
            ->selectRaw('COUNT(*) as new_leads')
            ->selectRaw(SqlAggregate::countFilter('*', 'software_purchased = true').' as won_leads')
            ->selectRaw(SqlAggregate::countFilter('*', 'status IN ('.$lost.')').' as lost_leads')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Demo Scheduled'").' as demo_leads')
            ->groupBy(DB::raw(SqlDate::monthBucket('created_at')))
            ->orderBy(DB::raw(SqlDate::monthBucket('created_at')))
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'new_leads' => (int) $row->new_leads,
                'won_leads' => (int) $row->won_leads,
                'lost_leads' => (int) $row->lost_leads,
                'demo_leads' => (int) $row->demo_leads,
                'conversion_rate_pct' => (int) $row->new_leads
                    ? round(((int) $row->won_leads / (int) $row->new_leads) * 100, 1)
                    : 0,
            ])
            ->all();

        return [
            'slug' => 'monthly_trends',
            'label' => config('reports.reports.monthly_trends.label'),
            'summary' => [
                'months' => $months,
                'total_new_leads' => array_sum(array_column($rows, 'new_leads')),
                'total_won_leads' => array_sum(array_column($rows, 'won_leads')),
            ],
            'columns' => [
                'month' => 'Month',
                'new_leads' => 'New Leads',
                'won_leads' => 'Won',
                'lost_leads' => 'Lost',
                'demo_leads' => 'Demos',
                'conversion_rate_pct' => 'Conversion %',
            ],
            'rows' => $rows,
        ];
    }

    private function cityAnalysis(array $filters): array
    {
        $lost = $this->quotedList(config('reports.lost_statuses', []));

        $rows = $this->scopedCaMasterQuery($filters)
            ->join('cities', 'cities.city_id', '=', 'ca_masters.city_id')
            ->when($filters['from'], fn ($q) => $q->where('ca_masters.created_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('ca_masters.created_at', '<=', $filters['to']))
            ->selectRaw('cities.city_name as city')
            ->selectRaw('COUNT(*) as total_leads')
            ->selectRaw(SqlAggregate::countFilter('*', 'ca_masters.software_purchased = true').' as won_leads')
            ->selectRaw(SqlAggregate::countFilter('*', 'ca_masters.status IN ('.$lost.')').' as lost_leads')
            ->selectRaw(SqlAggregate::countFilter('*', "ca_masters.status = 'Hot'").' as hot_leads')
            ->groupBy('cities.city_name')
            ->orderByDesc('total_leads')
            ->get()
            ->map(fn ($row) => [
                'city' => $row->city,
                'total_leads' => (int) $row->total_leads,
                'won_leads' => (int) $row->won_leads,
                'lost_leads' => (int) $row->lost_leads,
                'hot_leads' => (int) $row->hot_leads,
                'conversion_rate_pct' => (int) $row->total_leads
                    ? round(((int) $row->won_leads / (int) $row->total_leads) * 100, 1)
                    : 0,
            ])
            ->all();

        return [
            'slug' => 'city_analysis',
            'label' => config('reports.reports.city_analysis.label'),
            'summary' => [
                'cities' => count($rows),
                'total_leads' => array_sum(array_column($rows, 'total_leads')),
            ],
            'columns' => [
                'city' => 'City',
                'total_leads' => 'Total Leads',
                'hot_leads' => 'Hot',
                'won_leads' => 'Won',
                'lost_leads' => 'Lost',
                'conversion_rate_pct' => 'Conversion %',
            ],
            'rows' => $rows,
        ];
    }

    private function lostLeadAnalysis(array $filters): array
    {
        $lost = $this->quotedList(config('reports.lost_statuses', []));

        $rows = $this->scopedCaMasterQuery($filters)
            ->leftJoin('cities', 'cities.city_id', '=', 'ca_masters.city_id')
            ->leftJoin('source_leads', 'source_leads.source_id', '=', 'ca_masters.source_id')
            ->leftJoin('lead_assignment_engines as lae', function ($join) {
                $join->on('lae.ca_id', '=', 'ca_masters.ca_id')->where('lae.status', '=', 'Active');
            })
            ->leftJoin('employees', 'employees.employee_id', '=', 'lae.employee_id')
            ->whereIn('ca_masters.status', config('reports.lost_statuses', []))
            ->when($filters['from'], fn ($q) => $q->where('ca_masters.updated_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('ca_masters.updated_at', '<=', $filters['to']))
            ->when($filters['city_id'], fn ($q) => $q->where('ca_masters.city_id', $filters['city_id']))
            ->select([
                'ca_masters.ca_id',
                'ca_masters.firm_name',
                'ca_masters.status',
                'cities.city_name as city',
                'source_leads.source_name as source',
                'employees.name as executive',
                'ca_masters.updated_at',
            ])
            ->orderByDesc('ca_masters.updated_at')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'ca_id' => (string) $row->ca_id,
                'firm_name' => $row->firm_name,
                'status' => $row->status,
                'city' => $row->city ?? '—',
                'source' => $row->source ?? '—',
                'executive' => $row->executive ?? 'Unassigned',
                'updated_at' => $row->updated_at?->toDateString(),
            ])
            ->all();

        $summaryRow = $this->scopedCaMasterQuery($filters)
            ->when($filters['from'], fn ($q) => $q->where('updated_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('updated_at', '<=', $filters['to']))
            ->selectRaw(SqlAggregate::countFilter('*', 'status IN ('.$lost.')').' as lost_leads')
            ->first();

        return [
            'slug' => 'lost_lead_analysis',
            'label' => config('reports.reports.lost_lead_analysis.label'),
            'summary' => [
                'lost_leads' => (int) ($summaryRow->lost_leads ?? 0),
                'listed_rows' => count($rows),
            ],
            'columns' => [
                'firm_name' => 'Firm',
                'status' => 'Status',
                'city' => 'City',
                'source' => 'Source',
                'executive' => 'Employee',
                'updated_at' => 'Updated',
            ],
            'rows' => $rows,
        ];
    }

    private function channelCampaignStats($campaignQuery, $logQuery, string $statusColumn, ?Carbon $from, ?Carbon $to): array
    {
        $campaignsTotal = (clone $campaignQuery)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->count();

        $logStats = (clone $logQuery)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw(SqlAggregate::countFilter('*', "{$statusColumn} = 'Delivered'").' as delivered')
            ->selectRaw(SqlAggregate::countFilter('*', "{$statusColumn} = 'Failed'").' as failed')
            ->selectRaw('COUNT(*) as messages_total')
            ->first();

        $messagesTotal = (int) ($logStats->messages_total ?? 0);
        $delivered = (int) ($logStats->delivered ?? 0);

        return [
            'campaigns_total' => $campaignsTotal,
            'messages_total' => $messagesTotal,
            'delivered' => $delivered,
            'failed' => (int) ($logStats->failed ?? 0),
            'delivery_rate_pct' => $messagesTotal ? round(($delivered / $messagesTotal) * 100, 1) : 0,
        ];
    }

    private function dailyCallsSeries(array $filters): array
    {
        return $this->scopedFollowUpQuery($filters)
            ->when($filters['from'], fn ($q) => $q->where('scheduled_date', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('scheduled_date', '<=', $filters['to']))
            ->selectRaw('DATE(scheduled_date) as label')
            ->selectRaw('COUNT(*) as value')
            ->groupBy(DB::raw('DATE(scheduled_date)'))
            ->orderBy('label')
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'value' => (int) $row->value])
            ->all();
    }

    private function demoRatioSeries(array $filters): array
    {
        return $this->scopedCaMasterQuery($filters)
            ->when($filters['from'], fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('created_at', '<=', $filters['to']))
            ->selectRaw(SqlDate::weekLabel('created_at'))
            ->selectRaw(SqlAggregate::roundPercentOfTotal("status = 'Demo Scheduled'").' as value')
            ->groupBy(DB::raw(SqlDate::weekBucket('created_at')))
            ->orderBy(DB::raw(SqlDate::weekBucket('created_at')))
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'value' => (float) ($row->value ?? 0)])
            ->all();
    }

    private function conversionSeries(array $filters): array
    {
        return $this->scopedCaMasterQuery($filters)
            ->when($filters['from'], fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('created_at', '<=', $filters['to']))
            ->selectRaw(SqlDate::weekLabel('created_at'))
            ->selectRaw(SqlAggregate::roundPercentOfTotal('software_purchased = true').' as value')
            ->groupBy(DB::raw(SqlDate::weekBucket('created_at')))
            ->orderBy(DB::raw(SqlDate::weekBucket('created_at')))
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'value' => (float) ($row->value ?? 0)])
            ->all();
    }

    private function sourceBreakdownSeries(array $filters): array
    {
        return $this->scopedCaMasterQuery($filters)
            ->join('source_leads', 'source_leads.source_id', '=', 'ca_masters.source_id')
            ->when($filters['from'], fn ($q) => $q->where('ca_masters.created_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($q) => $q->where('ca_masters.created_at', '<=', $filters['to']))
            ->selectRaw('source_leads.source_name as label')
            ->selectRaw('COUNT(*) as value')
            ->groupBy('source_leads.source_name')
            ->orderByDesc('value')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'value' => (int) $row->value])
            ->all();
    }

    private function sourceBreakdownRows(array $filters): array
    {
        return collect($this->sourceBreakdownSeries($filters))
            ->map(fn ($row) => ['source' => $row['label'], 'count' => $row['value']])
            ->all();
    }

    private function targetAchievementSeries(array $employeeRows): array
    {
        return collect($employeeRows)
            ->take(8)
            ->map(fn ($row) => ['label' => $row['employee_name'], 'value' => (float) $row['achievement_pct']])
            ->values()
            ->all();
    }

    private function chartFromRows(array $rows, string $labelKey, string $valueKey): array
    {
        return collect($rows)
            ->take(8)
            ->map(fn ($row) => ['label' => $row[$labelKey], 'value' => (float) $row[$valueKey]])
            ->values()
            ->all();
    }

    private function scopedCaMasterQuery(array $filters): Builder
    {
        $query = CaMaster::query()->countableInStatistics();

        if (! empty($filters['employee_id'])) {
            $this->employeeDataScope->scopeCaMasterQuery($query, (int) $filters['employee_id']);
        }

        return $query;
    }

    private function scopedFollowUpQuery(array $filters): Builder
    {
        $query = FollowUp::query();

        if (! empty($filters['employee_id'])) {
            $this->employeeDataScope->scopeFollowUpQuery($query, (int) $filters['employee_id']);
        }

        return $query;
    }

    private function parseFilters(array $params): array
    {
        $defaultDays = (int) config('reports.default_days', 30);

        $employeeId = ! empty($params['employee_id']) ? (int) $params['employee_id'] : null;
        $scopedId = $this->employeeDataScope->scopedEmployeeId(auth()->user());

        if ($scopedId !== null) {
            if ($employeeId && $employeeId !== $scopedId) {
                $this->employeeDataScope->logDenied('report_filter_override', auth()->user(), [
                    'requested_employee_id' => $employeeId,
                ]);
            }
            $employeeId = $scopedId;
        }

        return [
            'from' => ! empty($params['from'])
                ? Carbon::parse($params['from'])->startOfDay()
                : now()->subDays($defaultDays)->startOfDay(),
            'to' => ! empty($params['to'])
                ? Carbon::parse($params['to'])->endOfDay()
                : now()->endOfDay(),
            'months' => min(max((int) ($params['months'] ?? 6), 1), 24),
            'city_id' => ! empty($params['city_id']) ? (int) $params['city_id'] : null,
            'employee_id' => $employeeId,
        ];
    }

    private function filterMeta(array $filters): array
    {
        return [
            'from' => $filters['from']->toDateString(),
            'to' => $filters['to']->toDateString(),
            'months' => $filters['months'],
            'city_id' => $filters['city_id'],
            'employee_id' => $filters['employee_id'],
        ];
    }

    private function quotedList(array $values): string
    {
        return collect($values)
            ->map(fn (string $value) => DB::getPdo()->quote($value))
            ->implode(', ');
    }
}
