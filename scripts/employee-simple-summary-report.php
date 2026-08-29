<?php

/**
 * Simple employee summary for Soniya, Simran, Dev (production).
 * Run: /opt/alt/php83/usr/bin/php scripts/employee-simple-summary-report.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DemoResult;
use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\PurchasedCustomer;
use App\Models\YearlyEmployeeTarget;
use App\Services\Assignment\EmployeeTargetService;
use Carbon\Carbon;

$names = ['soniya', 'simran', 'dev'];
$today = Carbon::now(config('app.timezone', 'Asia/Kolkata'))->toDateString();
$targetService = app(EmployeeTargetService::class);

$emps = Employee::query()
    ->where(function ($q) use ($names) {
        foreach ($names as $n) {
            $q->orWhereRaw('LOWER(name) LIKE ?', ['%'.$n.'%']);
        }
    })
    ->orderBy('name')
    ->get(['employee_id', 'name', 'status']);

echo PHP_EOL.'=== EMPLOYEE SUMMARY REPORT (ALL TIME + TODAY TARGET) ==='.PHP_EOL;
echo 'Date: '.$today.' ('.config('app.timezone', 'Asia/Kolkata').')'.PHP_EOL.PHP_EOL;

foreach ($emps as $e) {
    $id = (int) $e->employee_id;

    $leadsAssigned = (int) LeadAssignmentEngine::query()
        ->join('ca_masters', 'ca_masters.ca_id', '=', 'lead_assignment_engines.ca_id')
        ->where('lead_assignment_engines.employee_id', $id)
        ->where('lead_assignment_engines.status', 'Active')
        ->whereNull('ca_masters.deleted_at')
        ->distinct('lead_assignment_engines.ca_id')
        ->count('lead_assignment_engines.ca_id');

    $demosScheduled = (int) DemoSchedule::query()
        ->where('employee_id', $id)
        ->whereNotIn('status', [DemoSchedule::STATUS_CANCELLED])
        ->count();

    $demosCompleted = (int) DemoSchedule::query()
        ->where('employee_id', $id)
        ->where('status', DemoSchedule::STATUS_COMPLETED)
        ->count();

    $purchased = (int) DemoResult::query()
        ->where('employee_id', $id)
        ->where('result', 'Purchased')
        ->count();

    $purchasedCustomers = (int) PurchasedCustomer::query()
        ->where('employee_id', $id)
        ->count();

    $progress = $targetService->todayProgress($id, $today);
    $todayBlock = $progress['today'] ?? [];
    $yearly = $progress['yearly'] ?? [];

    $demoTargetToday = (int) ($todayBlock['demo_target'] ?? 0);
    $demoAchievedToday = (int) ($todayBlock['demo_achieved'] ?? 0);
    $demoPctToday = (float) ($todayBlock['demo_pct'] ?? 0);

    $yearlyTarget = YearlyEmployeeTarget::query()
        ->where('employee_id', $id)
        ->where('target_year', (int) Carbon::parse($today)->year)
        ->first();

    echo '--- '.$e->name.' ('.$e->status.') ---'.PHP_EOL;
    echo 'Leads assigned (active):     '.$leadsAssigned.PHP_EOL;
    echo 'Total demos scheduled:       '.$demosScheduled.PHP_EOL;
    echo 'Total demos completed:       '.$demosCompleted.PHP_EOL;
    echo 'Total purchased (demo):      '.$purchased.PHP_EOL;
    echo 'Total purchased (customers): '.$purchasedCustomers.PHP_EOL;
    echo 'Today daily demo target:     '.$demoTargetToday.PHP_EOL;
    echo 'Today demo achieved:         '.$demoAchievedToday.' ('.$demoPctToday.'%)'.PHP_EOL;
    echo 'Note: achieved = demos SCHEDULED today (CRM target rule)'.PHP_EOL;
    if ($yearly) {
        echo 'Yearly daily demo rate:      '.(int) $yearlyTarget->demo_target.PHP_EOL;
    }
    if (($yearly['has_target'] ?? false) === true) {
        echo 'Year-to-date demos done:     '.(int) ($yearly['ytd_demo_achieved'] ?? 0).' / '.(int) ($yearly['yearly_demo_target'] ?? 0).PHP_EOL;
    } else {
        echo 'Yearly target:               not set'.PHP_EOL;
    }
    echo PHP_EOL;
}

foreach ($names as $n) {
    if ($emps->first(fn ($e) => str_contains(strtolower($e->name), $n)) === null) {
        echo 'WARNING: No employee matched: '.$n.PHP_EOL;
    }
}
