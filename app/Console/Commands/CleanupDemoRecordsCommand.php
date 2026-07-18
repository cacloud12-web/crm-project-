<?php

namespace App\Console\Commands;

use App\Models\DemoProvider;
use App\Models\Employee;
use App\Models\User;
use App\Services\User\UserLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Explicit demo cleanup — NEVER run from migrate, deploy, seed, or boot.
 *
 * Only acts on reliable technical markers:
 *   - demo_providers.is_demo = true  → soft-clear meeting link + deactivate (does not hard-delete history)
 *   - users/employees with @ca.local / @example.local / @example.test / @test.local
 *     → deactivate + soft-delete when safe; skip Super Admins and historically protected users
 *
 * Never matches by personal name or guessing.
 */
class CleanupDemoRecordsCommand extends Command
{
    protected $signature = 'crm:cleanup-demo-records
                            {--force : Required to apply changes}
                            {--dry-run : Show actions without writing}';

    protected $description = 'Clean only marker-matched demo/test records (explicit; never automatic)';

    public function handle(UserLifecycleService $lifecycle): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing production cleanup without --force. Prefer crm:audit-demo-records first.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->option('dry-run')) {
            $this->error('Refusing to mutate data. Re-run with --dry-run or --force.');
            $this->line('Always audit first: php artisan crm:audit-demo-records');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN — no writes.');
        }

        $stats = [
            'providers_cleared' => 0,
            'users_soft_deleted' => 0,
            'users_skipped' => 0,
            'employees_soft_deleted' => 0,
            'employees_skipped' => 0,
        ];

        $stats['providers_cleared'] = $this->cleanupMarkedProviders($dryRun);
        [$stats['users_soft_deleted'], $stats['users_skipped']] = $this->cleanupDomainUsers($lifecycle, $dryRun);
        [$stats['employees_soft_deleted'], $stats['employees_skipped']] = $this->cleanupDomainEmployees($dryRun);

        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->info($dryRun ? 'Dry run complete.' : 'Marker-based cleanup complete.');

        return self::SUCCESS;
    }

    private function cleanupMarkedProviders(bool $dryRun): int
    {
        if (! Schema::hasTable('demo_providers') || ! Schema::hasColumn('demo_providers', 'is_demo')) {
            $this->warn('is_demo column missing — run migrations, then retry. No providers changed.');

            return 0;
        }

        $ids = DemoProvider::query()->where('is_demo', true)->pluck('id');
        if ($ids->isEmpty()) {
            $this->line('No is_demo providers to clear.');

            return 0;
        }

        if (! $dryRun) {
            DemoProvider::query()->whereIn('id', $ids)->update([
                'default_meeting_link' => null,
                'is_active' => false,
                'updated_at' => now(),
            ]);
        }

        $this->line('Marked demo providers cleared (link null, deactivated): '.$ids->implode(', '));

        return $ids->count();
    }

    /** @return array{0:int,1:int} */
    private function cleanupDomainUsers(UserLifecycleService $lifecycle, bool $dryRun): array
    {
        $deleted = 0;
        $skipped = 0;

        $users = User::query()->where(function ($q) {
            foreach (AuditDemoRecordsCommand::TEST_EMAIL_DOMAINS as $domain) {
                $q->orWhere('email', 'like', '%@'.$domain);
            }
        })->get();

        foreach ($users as $user) {
            try {
                $lifecycle->assertCanDeleteUser($user);
            } catch (ValidationException $e) {
                $this->warn('Skip user #'.$user->id.' ('.$user->email.'): '.collect($e->errors())->flatten()->first());
                $skipped++;

                continue;
            }

            if ($this->userHasHistoricalReferences((int) $user->id)) {
                if (! $dryRun) {
                    $user->update(['is_active' => false]);
                }
                $this->line('Deactivated (history retained) user #'.$user->id);
                $skipped++;

                continue;
            }

            if (! $dryRun) {
                $lifecycle->deactivateAndSoftDelete($user);
            }
            $deleted++;
        }

        return [$deleted, $skipped];
    }

    /** @return array{0:int,1:int} */
    private function cleanupDomainEmployees(bool $dryRun): array
    {
        $deleted = 0;
        $skipped = 0;

        $employees = Employee::query()->where(function ($q) {
            foreach (AuditDemoRecordsCommand::TEST_EMAIL_DOMAINS as $domain) {
                $q->orWhere('email_id', 'like', '%@'.$domain);
            }
        })->get();

        foreach ($employees as $employee) {
            if ($this->employeeHasHistoricalReferences((int) $employee->employee_id)) {
                if (! $dryRun) {
                    $employee->update(['status' => 'Inactive']);
                }
                $this->line('Deactivated employee #'.$employee->employee_id.' (history retained)');
                $skipped++;

                continue;
            }

            if (! $dryRun) {
                $employee->delete();
            }
            $deleted++;
        }

        return [$deleted, $skipped];
    }

    private function userHasHistoricalReferences(int $userId): bool
    {
        foreach (['activity_logs' => 'user_id', 'employee_attendances' => 'marked_by'] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                if (DB::table($table)->where($column, $userId)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function employeeHasHistoricalReferences(int $employeeId): bool
    {
        $checks = [
            'lead_assignment_engines' => 'employee_id',
            'follow_ups' => 'employee_id',
            'employee_attendances' => 'employee_id',
            'assignment_histories' => 'to_employee_id',
        ];

        foreach ($checks as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                if (DB::table($table)->where($column, $employeeId)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}
