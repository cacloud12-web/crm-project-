<?php

/**
 * Full employee activity audit (read-only): demos, follow-ups, calls, purchases + integrity flags.
 *
 * Usage on production:
 *   /opt/alt/php83/usr/bin/php scripts/employee-activity-audit-export.php
 *   /opt/alt/php83/usr/bin/php scripts/employee-activity-audit-export.php soniya,simran,modu,dev
 *   /opt/alt/php83/usr/bin/php scripts/employee-activity-audit-export.php all 20
 *
 * Args:
 *   1) comma-separated employee name fragments, or "all" (default: all)
 *   2) days (0 = all time, default 20)
 *
 * Output: storage/app/audits/employee-activity-audit.json
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CallLog;
use App\Models\CaMaster;
use App\Models\DemoResult;
use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\PurchasedCustomer;
use App\Services\Assignment\DailyEmployeeTargetProgressService;
use App\Services\Assignment\EmployeeTargetService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$timezone = config('app.timezone', 'Asia/Kolkata');
$nameFilterRaw = trim((string) ($argv[1] ?? 'all'));
$days = max(0, (int) ($argv[2] ?? 20));
$exportAllEmployees = $nameFilterRaw === '' || strtolower($nameFilterRaw) === 'all';

$nameFragments = $exportAllEmployees ? [] : array_values(array_filter(array_map(
    static fn (string $part) => strtolower(trim($part)),
    explode(',', $nameFilterRaw)
)));

$to = Carbon::now($timezone)->endOfDay();
$from = $days > 0
    ? Carbon::now($timezone)->subDays($days - 1)->startOfDay()
    : null;

$openFollowupStatuses = ['Pending', 'Scheduled', 'Open', 'Overdue'];
$completedFollowupStatuses = ['Completed', 'Closed'];

$targetService = app(EmployeeTargetService::class);
$progressService = app(DailyEmployeeTargetProgressService::class);

$employeesQuery = Employee::query()->orderBy('name');
if ($nameFragments !== []) {
    $employeesQuery->where(function ($query) use ($nameFragments) {
        foreach ($nameFragments as $fragment) {
            $query->orWhereRaw('LOWER(name) LIKE ?', ['%'.$fragment.'%']);
        }
    });
}
$employees = $employeesQuery->get(['employee_id', 'name', 'email_id', 'status', 'role']);

if ($employees->isEmpty()) {
    fwrite(STDERR, "No employees matched name filter: {$nameFilterRaw}\n");
    exit(1);
}

$employeeIds = $employees->pluck('employee_id')->map(static fn ($id) => (int) $id)->all();

$applyDate = static function ($query, string $column) use ($from, $to) {
    if ($from === null) {
        return $query;
    }

    return $query->whereBetween($column, [$from, $to]);
};

$fmt = static function ($dt) use ($timezone): ?string {
    if ($dt === null) {
        return null;
    }

    return Carbon::parse($dt)->timezone($timezone)->format('Y-m-d H:i');
};

$leadRow = static function (?CaMaster $lead): array {
    if (! $lead) {
        return [
            'ca_id' => null,
            'firm_name' => null,
            'ca_name' => null,
            'mobile_no' => null,
        'lead_status' => null,
        'software_purchased' => null,
        'missing_lead' => true,
        ];
    }

    return [
        'ca_id' => $lead->ca_id,
        'firm_name' => $lead->firm_name,
        'ca_name' => $lead->ca_name,
        'mobile_no' => $lead->mobile_no,
        'lead_status' => $lead->status,
        'software_purchased' => (bool) $lead->software_purchased,
        'missing_lead' => false,
    ];
};

$integrityIssues = [];

$employeeReports = [];

foreach ($employees as $employee) {
    $employeeId = (int) $employee->employee_id;

    // --- Demos (demo_schedules) ---
    $demosQuery = DemoSchedule::query()
        ->with([
            'result:id,demo_schedule_id,result,notes,created_at,employee_id',
            'lead:ca_id,firm_name,ca_name,mobile_no,status,software_purchased',
            'followUp:followup_id,followup_type,status,scheduled_date',
        ])
        ->where('employee_id', $employeeId);
    $applyDate($demosQuery, 'created_at');
    $demos = $demosQuery->orderBy('created_at')->get();

    $demosCompletedQuery = DemoSchedule::query()
        ->with(['result', 'lead:ca_id,firm_name,ca_name,mobile_no,status,software_purchased'])
        ->where('employee_id', $employeeId)
        ->where('status', DemoSchedule::STATUS_COMPLETED);
    if ($from !== null) {
        $demosCompletedQuery->whereBetween('updated_at', [$from, $to]);
    }
    $demosCompleted = $demosCompletedQuery->orderBy('updated_at')->get();

    $demoStatusCounts = [
        'scheduled' => 0,
        'completed' => 0,
        'rescheduled' => 0,
        'cancelled' => 0,
        'missed' => 0,
    ];
    $demoOutcomeCounts = [];
    $demoRows = [];
    $demoCaIds = [];

    foreach ($demos as $demo) {
        $status = (string) $demo->status;
        if (isset($demoStatusCounts[$status])) {
            $demoStatusCounts[$status]++;
        }
        $demoCaIds[] = (int) $demo->ca_id;

        $outcome = $demo->result?->result;
        if ($outcome) {
            $demoOutcomeCounts[$outcome] = ($demoOutcomeCounts[$outcome] ?? 0) + 1;
        }

        $issues = [];
        if (! $demo->lead) {
            $issues[] = 'missing_lead';
        }
        if (! $demo->followup_id) {
            $issues[] = 'demo_without_followup_link';
        }
        if ($status === DemoSchedule::STATUS_COMPLETED && ! $demo->result) {
            $issues[] = 'completed_demo_without_result';
        }
        if ($demo->result && (int) $demo->result->employee_id !== $employeeId) {
            $issues[] = 'result_employee_mismatch';
        }
        if ($outcome === 'Purchased' && ! PurchasedCustomer::query()->where('demo_schedule_id', $demo->id)->exists()) {
            $issues[] = 'purchased_outcome_without_purchase_record';
        }

        if ($issues !== []) {
            $integrityIssues[] = [
                'employee' => $employee->name,
                'type' => 'demo',
                'id' => $demo->id,
                'ca_id' => $demo->ca_id,
                'firm_name' => $demo->firm_name ?: $demo->lead?->firm_name,
                'issues' => $issues,
            ];
        }

        $demoRows[] = array_merge($leadRow($demo->lead), [
            'demo_id' => $demo->id,
            'demo_at' => $fmt($demo->demo_at),
            'scheduled_on' => $fmt($demo->created_at),
            'updated_at' => $fmt($demo->updated_at),
            'status' => $status,
            'followup_id' => $demo->followup_id,
            'followup_type' => $demo->followUp?->followup_type,
            'followup_status' => $demo->followUp?->status,
            'outcome' => $outcome,
            'outcome_notes' => $demo->result?->notes,
            'outcome_at' => $fmt($demo->result?->created_at),
            'demo_provider' => $demo->demo_provider_name,
            'team_size' => $demo->team_size,
            'integrity_flags' => $issues,
        ]);
    }

    // Duplicate demos same ca same day
    $dupes = collect($demoCaIds)
        ->filter()
        ->countBy()
        ->filter(static fn (int $count) => $count > 1);
    foreach ($dupes as $caId => $count) {
        $integrityIssues[] = [
            'employee' => $employee->name,
            'type' => 'demo_duplicate_ca',
            'ca_id' => (int) $caId,
            'count' => $count,
            'issues' => ['multiple_demo_schedules_same_ca_in_period'],
        ];
    }

    // --- Follow-ups ---
    $followupsQuery = FollowUp::query()
        ->with(['caMaster:ca_id,firm_name,ca_name,mobile_no,status,software_purchased'])
        ->where('employee_id', $employeeId);
    if ($from !== null) {
        $followupsQuery->where(function ($q) use ($from, $to) {
            $q->whereBetween('created_at', [$from, $to])
                ->orWhereBetween('scheduled_date', [$from, $to]);
        });
    }
    $followups = $followupsQuery->orderBy('scheduled_date')->get();

    $followupByType = [];
    $followupByStatus = [];
    $followupRows = [];

    foreach ($followups as $fu) {
        $type = (string) $fu->followup_type;
        $status = (string) $fu->status;
        $followupByType[$type] = ($followupByType[$type] ?? 0) + 1;
        $followupByStatus[$status] = ($followupByStatus[$status] ?? 0) + 1;

        $issues = [];
        if (! $fu->caMaster) {
            $issues[] = 'missing_lead';
        }
        if ($type === 'Demo Scheduled' && in_array($status, $openFollowupStatuses, true)) {
            $hasDemo = DemoSchedule::query()
                ->where('employee_id', $employeeId)
                ->where('ca_id', $fu->ca_id)
                ->whereIn('status', [
                    DemoSchedule::STATUS_SCHEDULED,
                    DemoSchedule::STATUS_RESCHEDULED,
                    DemoSchedule::STATUS_COMPLETED,
                ])
                ->exists();
            if (! $hasDemo) {
                $issues[] = 'open_demo_followup_without_demo_schedule';
            }
        }
        if ($type === 'Demo Completed' && ! DemoSchedule::query()
            ->where('employee_id', $employeeId)
            ->where('ca_id', $fu->ca_id)
            ->where('status', DemoSchedule::STATUS_COMPLETED)
            ->exists()) {
            $issues[] = 'demo_completed_followup_without_completed_demo';
        }

        if ($issues !== []) {
            $integrityIssues[] = [
                'employee' => $employee->name,
                'type' => 'followup',
                'id' => $fu->followup_id,
                'ca_id' => $fu->ca_id,
                'firm_name' => $fu->caMaster?->firm_name,
                'followup_type' => $type,
                'issues' => $issues,
            ];
        }

        $followupRows[] = array_merge($leadRow($fu->caMaster), [
            'followup_id' => $fu->followup_id,
            'followup_type' => $type,
            'status' => $status,
            'scheduled_date' => $fmt($fu->scheduled_date),
            'created_at' => $fmt($fu->created_at),
            'remarks' => $fu->remarks,
            'is_rescheduled' => (bool) $fu->is_rescheduled,
            'is_auto_generated' => (bool) $fu->is_auto_generated,
            'integrity_flags' => $issues,
        ]);
    }

    // --- Calls ---
    $callsQuery = CallLog::query()
        ->with(['lead:ca_id,firm_name,ca_name,mobile_no,status'])
        ->where('employee_id', $employeeId);
    $applyDate($callsQuery, 'called_at');
    $calls = $callsQuery->orderByDesc('called_at')->get();

    $callStatusCounts = [];
    $callRows = [];
    foreach ($calls as $call) {
        $st = (string) ($call->call_status ?: 'Unknown');
        $callStatusCounts[$st] = ($callStatusCounts[$st] ?? 0) + 1;

        $issues = [];
        if (! $call->lead) {
            $issues[] = 'missing_lead';
        }
        if (! $call->employee_id) {
            $issues[] = 'call_without_employee';
        }
        if ($issues !== []) {
            $integrityIssues[] = [
                'employee' => $employee->name,
                'type' => 'call',
                'id' => $call->id,
                'ca_id' => $call->ca_id,
                'issues' => $issues,
            ];
        }

        $callRows[] = array_merge($leadRow($call->lead), [
            'call_id' => $call->id,
            'called_at' => $fmt($call->called_at),
            'call_status' => $call->call_status,
            'call_note' => $call->call_note,
            'followup_id' => $call->followup_id,
            'integrity_flags' => $issues,
        ]);
    }

    // --- Purchases ---
    $purchasesQuery = PurchasedCustomer::query()
        ->with(['lead:ca_id,firm_name,ca_name,mobile_no,status,software_purchased'])
        ->where('employee_id', $employeeId);
    if ($from !== null) {
        $purchasesQuery->where(function ($q) use ($from, $to) {
            $q->whereBetween('purchase_date', [$from->toDateString(), $to->toDateString()])
                ->orWhereBetween('created_at', [$from, $to]);
        });
    }
    $purchases = $purchasesQuery->orderByDesc('purchase_date')->get();

    $purchaseRows = [];
    foreach ($purchases as $purchase) {
        $issues = [];
        if (! $purchase->lead) {
            $issues[] = 'missing_lead';
        }
        if ($purchase->lead && ! $purchase->lead->software_purchased) {
            $issues[] = 'purchase_record_but_lead_not_marked_purchased';
        }
        if ($issues !== []) {
            $integrityIssues[] = [
                'employee' => $employee->name,
                'type' => 'purchase',
                'id' => $purchase->id,
                'ca_id' => $purchase->ca_id,
                'firm_name' => $purchase->firm_name,
                'issues' => $issues,
            ];
        }

        $purchaseRows[] = array_merge($leadRow($purchase->lead), [
            'purchase_id' => $purchase->id,
            'purchase_date' => $purchase->purchase_date?->toDateString(),
            'software_name' => $purchase->software_name,
            'status' => $purchase->status,
            'demo_schedule_id' => $purchase->demo_schedule_id,
            'demo_result_id' => $purchase->demo_result_id,
            'notes' => $purchase->notes,
            'integrity_flags' => $issues,
        ]);
    }

    // Purchased via demo result but not in purchased_customers (employee scope)
    $purchasedDemoResults = DemoResult::query()
        ->with(['lead:ca_id,firm_name,ca_name,mobile_no,status,software_purchased', 'demoSchedule:id,ca_id,employee_id,status'])
        ->where('employee_id', $employeeId)
        ->where('result', 'Purchased');
    $applyDate($purchasedDemoResults, 'created_at');
    $purchasedResults = $purchasedDemoResults->get();

    $purchasedFromDemoOnly = [];
    foreach ($purchasedResults as $pr) {
        if (! PurchasedCustomer::query()->where('demo_result_id', $pr->id)->exists()) {
            $purchasedFromDemoOnly[] = [
                'demo_result_id' => $pr->id,
                'demo_schedule_id' => $pr->demo_schedule_id,
                'ca_id' => $pr->ca_id,
                'firm_name' => $pr->lead?->firm_name,
                'recorded_at' => $fmt($pr->created_at),
            ];
            $integrityIssues[] = [
                'employee' => $employee->name,
                'type' => 'demo_result_purchase',
                'id' => $pr->id,
                'ca_id' => $pr->ca_id,
                'issues' => ['demo_result_purchased_without_purchase_record'],
            ];
        }
    }

    // Leads with Purchased demo result by this employee but missing purchased_customers row
    $purchaseCaIds = PurchasedCustomer::query()->where('employee_id', $employeeId)->pluck('ca_id');
    $orphanPurchasedLeads = DemoResult::query()
        ->with(['lead:ca_id,firm_name,ca_name,mobile_no,status,software_purchased'])
        ->where('employee_id', $employeeId)
        ->where('result', 'Purchased')
        ->whereNotIn('ca_id', $purchaseCaIds)
        ->get()
        ->map(static fn (DemoResult $pr) => $pr->lead)
        ->filter()
        ->unique('ca_id')
        ->values();
    foreach ($orphanPurchasedLeads as $lead) {
        $integrityIssues[] = [
            'employee' => $employee->name,
            'type' => 'lead',
            'ca_id' => $lead->ca_id,
            'firm_name' => $lead->firm_name,
            'issues' => ['lead_marked_purchased_without_purchase_record'],
        ];
    }

    $leadsAssignedActive = (int) LeadAssignmentEngine::query()
        ->join('ca_masters', 'ca_masters.ca_id', '=', 'lead_assignment_engines.ca_id')
        ->where('lead_assignment_engines.employee_id', $employeeId)
        ->where('lead_assignment_engines.status', 'Active')
        ->whereNull('ca_masters.deleted_at')
        ->distinct('lead_assignment_engines.ca_id')
        ->count('lead_assignment_engines.ca_id');

    $leadsAssignedInPeriod = 0;
    $demoTargetPeriod = 0;
    $demoAchievedPeriod = 0;
    if ($from !== null) {
        $leadsAssignedInPeriod = (int) LeadAssignmentEngine::query()
            ->join('ca_masters', 'ca_masters.ca_id', '=', 'lead_assignment_engines.ca_id')
            ->where('lead_assignment_engines.employee_id', $employeeId)
            ->where('lead_assignment_engines.status', 'Active')
            ->whereNull('ca_masters.deleted_at')
            ->whereDate('lead_assignment_engines.assigned_date', '>=', $from->toDateString())
            ->whereDate('lead_assignment_engines.assigned_date', '<=', $to->toDateString())
            ->distinct('lead_assignment_engines.ca_id')
            ->count('lead_assignment_engines.ca_id');

        $cursor = $from->copy()->startOfDay();
        $endDay = $to->copy()->startOfDay();
        while ($cursor->lte($endDay)) {
            $dateString = $cursor->toDateString();
            $targets = $targetService->resolvedTargetsForDate($employeeId, $dateString);
            $demoTargetPeriod += (int) ($targets['demo_target'] ?? 0);
            $achievements = $progressService->achievementsForEmployee($employeeId, $dateString);
            $demoAchievedPeriod += (int) ($achievements['demo_completed'] ?? 0);
            $cursor->addDay();
        }
    }

    $todayTargets = $targetService->resolvedTargetsForDate($employeeId, now($timezone)->toDateString());
    $todayProgress = $progressService->achievementsForEmployee($employeeId, now($timezone)->toDateString());
    $demoTargetToday = (int) ($todayTargets['demo_target'] ?? 0);
    $demoAchievedToday = (int) ($todayProgress['demo_completed'] ?? 0);
    $demoAchievementPct = $demoTargetPeriod > 0
        ? round(($demoAchievedPeriod / $demoTargetPeriod) * 100, 1)
        : 0.0;

    $employeeReports[] = [
        'employee_id' => $employeeId,
        'employee_name' => $employee->name,
        'email' => $employee->email_id,
        'employee_status' => $employee->status,
        'role' => $employee->role,
        'summary' => [
            'leads_assigned_active' => $leadsAssignedActive,
            'leads_assigned_in_period' => $leadsAssignedInPeriod,
            'demo_target_period' => $demoTargetPeriod,
            'demo_achieved_period' => $demoAchievedPeriod,
            'demo_achievement_pct' => $demoAchievementPct,
            'demo_target_today' => $demoTargetToday,
            'demo_achieved_today' => $demoAchievedToday,
            'demos_scheduled_created' => (int) $demos->whereNotIn('status', [DemoSchedule::STATUS_CANCELLED])->count(),
            'demos_still_open' => (int) $demos->where('status', DemoSchedule::STATUS_SCHEDULED)->count(),
            'demos_completed' => (int) $demosCompleted->count(),
            'demos_rescheduled' => (int) $demos->where('status', DemoSchedule::STATUS_RESCHEDULED)->count(),
            'demos_cancelled' => (int) $demos->where('status', DemoSchedule::STATUS_CANCELLED)->count(),
            'demos_missed' => (int) $demos->where('status', DemoSchedule::STATUS_MISSED)->count(),
            'demo_status_breakdown' => $demoStatusCounts,
            'demo_outcome_breakdown' => $demoOutcomeCounts,
            'followups_total' => $followups->count(),
            'followups_open_demo_scheduled' => (int) $followups
                ->where('followup_type', 'Demo Scheduled')
                ->whereIn('status', $openFollowupStatuses)
                ->count(),
            'followups_demo_completed' => (int) $followups->where('followup_type', 'Demo Completed')->count(),
            'followups_call_type' => (int) $followups->where('followup_type', 'Call')->count(),
            'followups_by_type' => $followupByType,
            'followups_by_status' => $followupByStatus,
            'calls_total' => $calls->count(),
            'calls_by_status' => $callStatusCounts,
            'purchases_total' => $purchases->count(),
            'purchased_demo_results' => $purchasedResults->count(),
            'integrity_issue_count' => collect($integrityIssues)->where('employee', $employee->name)->count(),
        ],
        'demos' => $demoRows,
        'demos_completed_detail' => $demosCompleted->map(static function (DemoSchedule $demo) use ($fmt, $leadRow) {
            return array_merge($leadRow($demo->lead), [
                'demo_id' => $demo->id,
                'completed_at' => $fmt($demo->updated_at),
                'outcome' => $demo->result?->result,
                'outcome_notes' => $demo->result?->notes,
            ]);
        })->values()->all(),
        'followups' => $followupRows,
        'calls' => $callRows,
        'purchases' => $purchaseRows,
        'purchased_demo_results_without_record' => $purchasedFromDemoOnly,
        'orphan_purchased_leads' => $orphanPurchasedLeads->map(static fn (?CaMaster $l) => $l ? [
            'ca_id' => $l->ca_id,
            'firm_name' => $l->firm_name,
            'ca_name' => $l->ca_name,
            'mobile_no' => $l->mobile_no,
            'status' => $l->status,
            'software_purchased' => (bool) $l->software_purchased,
        ] : null)->filter()->values()->all(),
    ];
}

// Employees requested but not found
$allEmployees = Employee::query()->orderBy('name')->get(['employee_id', 'name']);
$matchedNames = $employees->pluck('name')->map(static fn ($n) => strtolower($n))->all();
$notFound = [];
foreach ($nameFragments as $fragment) {
    $found = false;
    foreach ($matchedNames as $name) {
        if (str_contains($name, $fragment)) {
            $found = true;
            break;
        }
    }
    if (! $found) {
        $suggestions = $allEmployees->filter(static fn ($e) => str_contains(strtolower($e->name), substr($fragment, 0, 3)))->pluck('name')->take(5)->values()->all();
        $notFound[] = ['fragment' => $fragment, 'suggestions' => $suggestions];
    }
}

$grand = [
    'leads_assigned_active' => array_sum(array_column(array_column($employeeReports, 'summary'), 'leads_assigned_active')),
    'leads_assigned_in_period' => array_sum(array_column(array_column($employeeReports, 'summary'), 'leads_assigned_in_period')),
    'demo_target_period' => array_sum(array_column(array_column($employeeReports, 'summary'), 'demo_target_period')),
    'demo_achieved_period' => array_sum(array_column(array_column($employeeReports, 'summary'), 'demo_achieved_period')),
    'demos_scheduled_created' => array_sum(array_column(array_column($employeeReports, 'summary'), 'demos_scheduled_created')),
    'demos_completed' => array_sum(array_column(array_column($employeeReports, 'summary'), 'demos_completed')),
    'followups_total' => array_sum(array_column(array_column($employeeReports, 'summary'), 'followups_total')),
    'calls_total' => array_sum(array_column(array_column($employeeReports, 'summary'), 'calls_total')),
    'purchases_total' => array_sum(array_column(array_column($employeeReports, 'summary'), 'purchases_total')),
    'purchased_demo_results' => array_sum(array_column(array_column($employeeReports, 'summary'), 'purchased_demo_results')),
];

$report = [
    'generated_at' => now($timezone)->toIso8601String(),
    'timezone' => $timezone,
    'period' => $from === null ? 'all_time' : ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
    'name_filter' => $nameFragments,
    'employees_matched' => $employees->pluck('name')->values()->all(),
    'employees_not_matched' => $notFound,
    'grand_totals' => $grand,
    'integrity_issues' => $integrityIssues,
    'integrity_issue_count' => count($integrityIssues),
    'employees' => $employeeReports,
];

$outDir = $root.'/storage/app/audits';
if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$jsonPath = $outDir.'/employee-activity-audit.json';
file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Exported: {$jsonPath}\n";
echo 'Period: '.($from === null ? 'ALL TIME' : "{$from->toDateString()} to {$to->toDateString()}")."\n";
echo 'Employees: '.implode(', ', $employees->pluck('name')->all())."\n";
echo "Integrity issues: ".count($integrityIssues)."\n";
echo "Grand totals — demos scheduled: {$grand['demos_scheduled_created']}, completed: {$grand['demos_completed']}, followups: {$grand['followups_total']}, calls: {$grand['calls_total']}, purchases: {$grand['purchases_total']}\n";
