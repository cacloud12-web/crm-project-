<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Services\Cache\CrmCacheService;
use App\Services\User\UserLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class PurgeEmployeesCommand extends Command
{
    protected $signature = 'employees:purge
                            {--force : Permanently delete all employee records}
                            {--dry-run : Preview actions without writing}';

    protected $description = 'Permanently remove all employees and their login accounts (keeps admins/managers)';

    public function handle(UserLifecycleService $lifecycle, CrmCacheService $cacheService): int
    {
        if (! $this->option('force') && ! $this->option('dry-run')) {
            $this->error('Refusing to mutate data. Re-run with --dry-run or --force.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN — no writes.');
        }

        $employees = Employee::withTrashed()
            ->with('user')
            ->orderBy('employee_id')
            ->get();

        if ($employees->isEmpty()) {
            $this->info('No employee records found.');

            return self::SUCCESS;
        }

        $purged = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            $user = $employee->user;
            if ($user) {
                try {
                    $lifecycle->assertCanDeleteUser($user);
                } catch (ValidationException $e) {
                    $this->warn('Skip employee #'.$employee->employee_id.' ('.$employee->name.'): '.collect($e->errors())->flatten()->first());
                    $skipped++;

                    continue;
                }
            }

            $this->line(($dryRun ? '[dry-run] ' : '').'Purge #'.$employee->employee_id.' '.$employee->name.' <'.$employee->email_id.'>');

            if ($dryRun) {
                $purged++;

                continue;
            }

            if ($user && $user->crm_role === 'employee') {
                $user->forceDelete();
            } elseif ($user) {
                $user->update(['is_active' => false]);
                $employee->update(['user_id' => null]);
            }

            $employee->forceDelete();
            $purged++;
        }

        if (! $dryRun) {
            $cacheService->forgetDashboardMetrics();
            $cacheService->forgetEmployeeRankings();
            $cacheService->forgetAssignmentWidgets();
        }

        $this->table(['Metric', 'Count'], [
            ['employees_purged', $purged],
            ['employees_skipped', $skipped],
        ]);
        $this->info($dryRun ? 'Dry run complete.' : 'Employee purge complete. Refresh the dashboard in your browser.');

        return self::SUCCESS;
    }
}
