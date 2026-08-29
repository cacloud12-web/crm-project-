<?php

/**
 * One-time production fix: sync orphaned Demo Scheduled + Completed follow-ups
 * into Demo Completed and demo_schedules/demo_results for reports.
 *
 * Run on production:
 *   cd ~/domains/crm.caclouddesk.com/public_html
 *   /opt/alt/php83/usr/bin/php scripts/fix-demo-followups-production.php
 *
 * Or paste via heredoc — see RUN-DEMO-AUDIT-PRODUCTION.txt
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DemoResult;
use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\FollowUp;

$names = ['soniya', 'simran', 'monu', 'modu', 'dev'];
$open = ['Pending', 'Scheduled', 'Open', 'Overdue'];
$done = ['Completed', 'Closed'];
$valid = config('lead_workflow.demo_results', ['Thinking', 'Purchased', 'Not Interested']);

$emps = Employee::query()
    ->where(function ($q) use ($names) {
        foreach ($names as $n) {
            $q->orWhereRaw('LOWER(name) LIKE ?', ['%'.$n.'%']);
        }
    })
    ->orderBy('name')
    ->get(['employee_id', 'name', 'status']);

function printTable(string $title, $emps, array $open, array $done): void
{
    echo PHP_EOL.$title.PHP_EOL;
    echo str_pad('Employee', 26).'OpenSched DoneFU Orphan Sched DoneRpt Purch'.PHP_EOL;
    echo str_repeat('-', 78).PHP_EOL;

    foreach ($emps as $e) {
        $id = (int) $e->employee_id;
        $openSched = FollowUp::where('employee_id', $id)->where('followup_type', 'Demo Scheduled')->whereIn('status', $open)->count();
        $doneFu = FollowUp::where('employee_id', $id)->where('followup_type', 'Demo Completed')->count();
        $orphan = FollowUp::where('employee_id', $id)->where('followup_type', 'Demo Scheduled')->whereIn('status', $done)->count();
        $sched = DemoSchedule::where('employee_id', $id)->whereNotIn('status', ['cancelled'])->count();
        $doneRpt = DemoSchedule::where('employee_id', $id)->where('status', 'completed')->count();
        $purch = DemoResult::where('employee_id', $id)->where('result', 'Purchased')->count();
        echo str_pad($e->name, 26).$openSched.'        '.$doneFu.'      '.$orphan.'      '.$sched.'     '.$doneRpt.'        '.$purch.PHP_EOL;
    }

    global $names;
    foreach ($names as $n) {
        $matched = $emps->first(fn ($e) => str_contains(strtolower($e->name), $n));
        if ($matched === null) {
            echo '!! NO EMPLOYEE MATCHED: '.$n.PHP_EOL;
        }
    }
}

printTable('=== BEFORE FIX ===', $emps, $open, $done);

$fixed = 0;
$results = 0;

FollowUp::query()
    ->where('followup_type', 'Demo Scheduled')
    ->whereIn('status', $done)
    ->orderBy('followup_id')
    ->chunkById(100, function ($rows) use (&$fixed, &$results, $valid) {
        foreach ($rows as $fu) {
            $outcome = trim((string) ($fu->outcome ?? ''));
            if ($outcome === '' || in_array($outcome, $valid, true) === false) {
                $r = strtolower((string) ($fu->remarks ?? ''));
                if (str_contains($r, 'purchased')) {
                    $outcome = 'Purchased';
                } elseif (str_contains($r, 'not interested')) {
                    $outcome = 'Not Interested';
                } else {
                    $outcome = 'Thinking';
                }
            }

            $fu->update([
                'followup_type' => 'Demo Completed',
                'outcome' => $outcome,
                'status' => 'Completed',
            ]);
            $fixed++;

            $schedule = DemoSchedule::query()
                ->where(function ($q) use ($fu) {
                    $q->where('followup_id', $fu->followup_id);
                    if ($fu->ca_id && $fu->employee_id) {
                        $q->orWhere(function ($n) use ($fu) {
                            $n->where('ca_id', $fu->ca_id)->where('employee_id', $fu->employee_id);
                        });
                    }
                })
                ->whereNotIn('status', ['cancelled'])
                ->whereDoesntHave('result')
                ->orderByDesc('id')
                ->first();

            if ($schedule) {
                DemoResult::create([
                    'demo_schedule_id' => $schedule->id,
                    'ca_id' => $schedule->ca_id,
                    'employee_id' => $schedule->employee_id ?: $fu->employee_id,
                    'result' => $outcome,
                    'notes' => $fu->remarks,
                ]);
                $schedule->update(['status' => 'completed']);
                $results++;
            }
        }
    }, 'followup_id');

echo PHP_EOL.'FIXED orphaned follow-ups: '.$fixed.PHP_EOL;
echo 'Demo results created for reports: '.$results.PHP_EOL;

printTable('=== AFTER FIX ===', $emps, $open, $done);
