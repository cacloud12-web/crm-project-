<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CrmQueueAuditCommand extends Command
{
    protected $signature = 'crm:queue-audit
                            {--clear-stale : Delete all rows from jobs and failed_jobs (local/demo only)}
                            {--force : Required with --clear-stale}';

    protected $description = 'Audit Laravel queue tables and optionally clear stale development backlog';

    public function handle(): int
    {
        $this->info('Queue table audit');
        $this->newLine();

        $tables = [
            'jobs' => 'Laravel database queue (active)',
            'failed_jobs' => 'Laravel failed jobs',
            'job_batches' => 'Laravel job batches',
            'queue_jobs' => 'CRM legacy queue table (future module)',
            'queue_logs' => 'CRM legacy queue logs (future module)',
        ];

        $rows = [];
        foreach ($tables as $table => $label) {
            $count = Schema::hasTable($table) ? DB::table($table)->count() : null;
            $rows[] = [$table, $label, $count ?? 'missing'];
        }

        $this->table(['Table', 'Purpose', 'Rows'], $rows);

        $this->newLine();
        $this->line('Production operations:');
        $this->line('  php artisan queue:work          Process jobs continuously');
        $this->line('  php artisan queue:work --once   Process one job');
        $this->line('  php artisan queue:failed        List failed jobs');
        $this->line('  php artisan queue:retry all     Retry failed jobs');
        $this->line('  php artisan queue:flush         Delete all failed jobs');
        $this->line('  php artisan queue:clear database  Clear pending database queue jobs');
        $this->line('  php artisan crm:queue-audit --clear-stale --force  Clear jobs + failed_jobs (local/demo)');

        if ($this->option('clear-stale')) {
            if (! $this->option('force')) {
                $this->error('Pass --force with --clear-stale to confirm deletion.');

                return self::FAILURE;
            }

            if (! app()->environment(['local', 'testing'])) {
                $this->error('Clearing queue tables is only allowed in local/testing environments.');

                return self::FAILURE;
            }

            $cleared = 0;
            foreach (['jobs', 'failed_jobs'] as $table) {
                if (Schema::hasTable($table)) {
                    $n = DB::table($table)->count();
                    DB::table($table)->delete();
                    $cleared += $n;
                    $this->warn("Cleared {$n} rows from {$table}");
                }
            }

            $this->info("Queue backlog cleared ({$cleared} rows).");
        }

        return self::SUCCESS;
    }
}
