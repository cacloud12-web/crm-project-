<?php

namespace App\Console\Commands;

use App\Services\CaReference\CaReferenceTableClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupAccidentalCaReferenceTablesCommand extends Command
{
    protected $signature = 'ca-reference:cleanup-accidental-tables
        {--dry-run : List keep/drop tables and migration rows without changing anything (default)}
        {--execute : Drop accidental CRM tables and purge accidental migration rows}';

    protected $description = 'Safely remove accidental CRM tables from the ca_reference database only';

    public function handle(CaReferenceTableClassifier $classifier): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = (bool) $this->option('dry-run') || ! $execute;

        if ($execute && $this->option('dry-run')) {
            $this->error('Pass either --dry-run or --execute, not both.');

            return self::FAILURE;
        }

        try {
            $report = $classifier->inspect();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Connection: ca_reference');
        $this->line('Database: '.$report['database']);
        $this->newLine();

        $this->info('Tables that will REMAIN ('.count($report['keep']).')');
        $this->table(['table', 'rows'], array_map(static fn ($r) => [$r['table'], $r['rows']], $report['keep']));

        $this->newLine();
        $this->warn('Tables that would be DROPPED ('.count($report['drop']).')');
        $this->table(['table', 'rows'], array_map(static fn ($r) => [$r['table'], $r['rows']], $report['drop']));

        if ($report['nonempty_drop'] !== []) {
            $this->newLine();
            $this->warn('Non-empty accidental tables (seed/data will be lost if executed):');
            foreach ($report['nonempty_drop'] as $row) {
                $this->line('  - '.$row['table'].' ('.$row['rows'].' rows)');
            }
        }

        if ($report['unclassified_nonempty'] !== []) {
            $this->newLine();
            $this->error('Refusing to proceed: non-empty tables are not clearly classified:');
            foreach ($report['unclassified_nonempty'] as $row) {
                $this->line('  - '.$row['table'].' ('.$row['rows'].' rows)');
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Dedicated migration rows to KEEP ('.count($report['keep_migration_rows']).')');
        foreach ($report['keep_migration_rows'] as $row) {
            $this->line('  [batch '.$row['batch'].'] '.$row['migration']);
        }

        $this->newLine();
        $this->warn('Accidental migration rows to DELETE ('.count($report['accidental_migrations']).')');
        foreach ($report['accidental_migrations'] as $row) {
            $this->line('  [batch '.$row['batch'].'] '.$row['migration']);
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry-run only. No tables were dropped.');
            $this->line('To execute after backup: php artisan ca-reference:cleanup-accidental-tables --execute');

            return self::SUCCESS;
        }

        if (! $this->confirm('Drop '.count($report['drop']).' accidental tables from ca_reference database "'.$report['database'].'"?', false)) {
            $this->warn('Aborted. No changes made.');

            return self::FAILURE;
        }

        if ($report['nonempty_drop'] !== []
            && ! $this->confirm('Some accidental tables have rows. Confirm permanent data loss for those seed tables?', false)) {
            $this->warn('Aborted. No changes made.');

            return self::FAILURE;
        }

        $connection = DB::connection(CaReferenceTableClassifier::CONNECTION);
        $dropped = [];
        $failed = [];

        try {
            $connection->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($report['drop'] as $entry) {
                $table = $entry['table'];
                try {
                    $connection->getSchemaBuilder()->drop($table);
                    $dropped[] = $table;
                    Log::info('ca_reference cleanup dropped table', [
                        'connection' => 'ca_reference',
                        'database' => $report['database'],
                        'table' => $table,
                        'rows_at_inspect' => $entry['rows'],
                    ]);
                    $this->line('Dropped: '.$table);
                } catch (Throwable $e) {
                    $failed[] = $table;
                    Log::error('ca_reference cleanup failed to drop table', [
                        'connection' => 'ca_reference',
                        'table' => $table,
                        'error' => $e->getMessage(),
                    ]);
                    $this->error('Failed: '.$table.' ('.$e->getMessage().')');
                }
            }

            if ($report['accidental_migrations'] !== []) {
                $ids = array_column($report['accidental_migrations'], 'id');
                foreach (array_chunk($ids, 200) as $chunk) {
                    $connection->table('migrations')->whereIn('id', $chunk)->delete();
                }
                $this->info('Removed '.count($ids).' accidental migration rows.');
            }
        } finally {
            try {
                $connection->statement('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable) {
                // sqlite or drivers without FK pragma
            }
        }

        $this->newLine();
        $this->info('Dropped '.count($dropped).' tables.');
        if ($failed !== []) {
            $this->error('Failed to drop: '.implode(', ', $failed));

            return self::FAILURE;
        }

        $this->info('Cleanup complete. Re-verify with: php artisan ca-reference:verify');

        return self::SUCCESS;
    }
}
