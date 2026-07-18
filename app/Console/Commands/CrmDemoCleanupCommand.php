<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoDataCleanupService;
use Illuminate\Console\Command;

class CrmDemoCleanupCommand extends Command
{
    protected $signature = 'crm:demo-cleanup
                            {--force : Required to remove QA/test transactional data}
                            {--no-seed : Cleanup only; do not run ManagerDemoSeeder}';

    protected $description = 'Remove QA/test CRM rows and reseed the Manager Demo dataset (5 leads, 3 employees, 3 assignments, 3 follow-ups, 3 campaigns)';

    public function handle(DemoDataCleanupService $cleanupService): int
    {
        if (app()->environment('production')) {
            $this->error('crm:demo-cleanup is blocked in production.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error('This command deletes QA/test transactional data. Re-run with --force to continue.');
            $this->line('Master tables (states, cities, source_leads, team_size_masters, role_masters, users) are preserved.');

            return self::FAILURE;
        }

        $this->warn('Cleaning QA/test data and preparing Manager Demo dataset...');

        $counts = $cleanupService->cleanup(! $this->option('no-seed'));

        $this->table(['Area', 'Rows removed'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());

        $this->info('Demo cleanup complete.');
        $this->line('Verify: php artisan tinker → CaMaster::count() should be 5 after reseed.');

        return self::SUCCESS;
    }
}
