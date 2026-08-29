<?php

/**
 * Full demo report export (read-only) for Excel generation.
 *
 * Usage on production:
 *   /opt/alt/php83/usr/bin/php scripts/demo-full-report-export.php
 *   /opt/alt/php83/usr/bin/php scripts/demo-full-report-export.php 20
 *
 * Output: storage/app/audits/demo-full-report.json
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DemoResult;
use App\Models\DemoSchedule;
use App\Models\Employee;
use Carbon\Carbon;

$days = max(1, (int) ($argv[1] ?? 20));
$timezone = config('app.timezone', 'Asia/Kolkata');
$to = Carbon::now($timezone)->endOfDay();
$from = Carbon::now($timezone)->subDays($days - 1)->startOfDay();

$outcomeLabels = [
    'Interested',
    'Thinking',
    'Purchasing',
    'Purchased',
    'Not Interested',
    'Next Week',
    'Next Month',
    'Left in between',
    'Hold',
];

$employees = Employee::query()
    ->orderBy('name')
    ->get(['employee_id', 'name', 'email_id', 'status', 'role']);

$demos = DemoSchedule::query()
    ->with([
        'result:id,demo_schedule_id,result,notes,created_at',
        'lead:ca_id,firm_name,ca_name,mobile_no,status',
    ])
    ->whereBetween('created_at', [$from, $to])
    ->orderBy('created_at')
    ->get();

$resultsInRange = DemoResult::query()
    ->whereBetween('created_at', [$from, $to])
    ->get()
    ->groupBy('employee_id');

$employeeReports = [];

foreach ($employees as $employee) {
    $employeeId = (int) $employee->employee_id;
    $employeeDemos = $demos->where('employee_id', $employeeId)->values();
    $employeeResults = $resultsInRange->get($employeeId, collect());

    $statusCounts = [
        'scheduled' => 0,
        'completed' => 0,
        'rescheduled' => 0,
        'cancelled' => 0,
        'missed' => 0,
    ];

    $outcomeCounts = array_fill_keys($outcomeLabels, 0);
    $detailRows = [];

    foreach ($employeeDemos as $demo) {
        $status = (string) $demo->status;
        if (isset($statusCounts[$status])) {
            $statusCounts[$status]++;
        }

        $result = $demo->result;
        $outcome = $result?->result;
        if ($outcome && isset($outcomeCounts[$outcome])) {
            $outcomeCounts[$outcome]++;
        }

        $detailRows[] = [
            'demo_id' => $demo->id,
            'ca_id' => $demo->ca_id,
            'firm_name' => $demo->firm_name ?: $demo->lead?->firm_name,
            'ca_name' => $demo->customer_name ?: $demo->lead?->ca_name,
            'mobile_no' => $demo->lead?->mobile_no,
            'lead_status' => $demo->lead?->status,
            'demo_at' => $demo->demo_at?->timezone($timezone)->format('Y-m-d H:i'),
            'scheduled_on' => $demo->created_at?->timezone($timezone)->format('Y-m-d H:i'),
            'updated_at' => $demo->updated_at?->timezone($timezone)->format('Y-m-d H:i'),
            'status' => $status,
            'demo_provider' => $demo->demo_provider_name,
            'team_size' => $demo->team_size,
            'meeting_link' => $demo->meeting_link,
            'notes' => $demo->notes,
            'outcome' => $outcome,
            'outcome_notes' => $result?->notes,
            'outcome_recorded_at' => $result?->created_at?->timezone($timezone)->format('Y-m-d H:i'),
        ];
    }

    $scheduledTotal = (int) $employeeDemos->where('status', '<>', DemoSchedule::STATUS_CANCELLED)->count();
    $completedInRange = (int) DemoSchedule::query()
        ->where('employee_id', $employeeId)
        ->where('status', DemoSchedule::STATUS_COMPLETED)
        ->whereBetween('updated_at', [$from, $to])
        ->count();

    $rescheduledInRange = (int) DemoSchedule::query()
        ->where('employee_id', $employeeId)
        ->where('status', DemoSchedule::STATUS_RESCHEDULED)
        ->whereBetween('updated_at', [$from, $to])
        ->count();

    $cancelledInRange = (int) DemoSchedule::query()
        ->where('employee_id', $employeeId)
        ->where('status', DemoSchedule::STATUS_CANCELLED)
        ->whereBetween('updated_at', [$from, $to])
        ->count();

    $missedInRange = (int) DemoSchedule::query()
        ->where('employee_id', $employeeId)
        ->where('status', DemoSchedule::STATUS_MISSED)
        ->whereBetween('updated_at', [$from, $to])
        ->count();

    $notInterested = (int) $employeeResults->where('result', 'Not Interested')->count();
    $stillOpen = (int) $employeeDemos->where('status', DemoSchedule::STATUS_SCHEDULED)->count();

    $employeeReports[] = [
        'employee_id' => $employeeId,
        'employee_name' => $employee->name,
        'email' => $employee->email_id,
        'employee_status' => $employee->status,
        'role' => $employee->role,
        'summary' => [
            'total_demos' => $scheduledTotal,
            'scheduled_created' => $scheduledTotal,
            'still_open' => $stillOpen,
            'completed' => $completedInRange,
            'rescheduled' => $rescheduledInRange,
            'cancelled' => $cancelledInRange,
            'missed' => $missedInRange,
            'not_interested' => $notInterested,
            'outcomes_recorded' => $employeeResults->count(),
            'status_breakdown' => $statusCounts,
            'outcome_breakdown' => $outcomeCounts,
        ],
        'demos' => $detailRows,
    ];
}

$grand = [
    'total_demos' => array_sum(array_column(array_column($employeeReports, 'summary'), 'total_demos')),
    'completed' => array_sum(array_column(array_column($employeeReports, 'summary'), 'completed')),
    'rescheduled' => array_sum(array_column(array_column($employeeReports, 'summary'), 'rescheduled')),
    'cancelled' => array_sum(array_column(array_column($employeeReports, 'summary'), 'cancelled')),
    'missed' => array_sum(array_column(array_column($employeeReports, 'summary'), 'missed')),
    'not_interested' => array_sum(array_column(array_column($employeeReports, 'summary'), 'not_interested')),
    'still_open' => array_sum(array_column(array_column($employeeReports, 'summary'), 'still_open')),
];

$report = [
    'generated_at' => now($timezone)->toIso8601String(),
    'timezone' => $timezone,
    'from' => $from->toDateString(),
    'to' => $to->toDateString(),
    'days' => $days,
    'grand_totals' => $grand,
    'employees' => $employeeReports,
];

$outDir = $root.'/storage/app/audits';
if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$jsonPath = $outDir.'/demo-full-report.json';
file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Exported: {$jsonPath}\n";
echo "Period: {$from->toDateString()} to {$to->toDateString()} ({$days} days)\n";
echo 'Employees: '.count($employeeReports)."\n";
echo 'Total demos: '.$grand['total_demos']."\n";
