<?php

namespace App\Console\Commands;

use App\Services\CaReference\CaReferenceTableClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class BackupCaReferenceSchemaCommand extends Command
{
    protected $signature = 'ca-reference:backup-schema
        {--output= : Optional output file path (default storage/app/backups/)}';

    protected $description = 'Write a schema-only SQL backup of ca_reference (no credentials printed)';

    public function handle(CaReferenceTableClassifier $classifier): int
    {
        try {
            $report = $classifier->inspect();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        $default = $dir.'/ca_reference_schema_'.date('Ymd_His').'.sql';
        $output = (string) ($this->option('output') ?: $default);

        $connection = DB::connection(CaReferenceTableClassifier::CONNECTION);
        $driver = $connection->getDriverName();
        $database = $report['database'];

        $lines = [
            '-- Schema-only backup for ca_reference',
            '-- Generated: '.now()->toIso8601String(),
            '-- Database: '.$database,
            '-- Driver: '.$driver,
            '-- NOTE: credentials are intentionally omitted',
            '',
            'SET FOREIGN_KEY_CHECKS=0;',
            '',
        ];

        $allTables = array_merge(
            array_column($report['keep'], 'table'),
            array_column($report['drop'], 'table'),
        );
        sort($allTables);

        foreach ($allTables as $table) {
            try {
                if ($driver === 'sqlite') {
                    $row = $connection->selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
                    $sql = $row->sql ?? null;
                    if (is_string($sql) && $sql !== '') {
                        $lines[] = '-- Table: '.$table;
                        $lines[] = $sql.';';
                        $lines[] = '';
                    }
                    continue;
                }

                $row = $connection->selectOne('SHOW CREATE TABLE `'.$table.'`');
                $create = $row->{'Create Table'} ?? ($row->Create_Table ?? null);
                if (! is_string($create) || $create === '') {
                    $this->warn('Could not dump create SQL for: '.$table);
                    continue;
                }
                $lines[] = '-- Table: '.$table;
                $lines[] = 'DROP TABLE IF EXISTS `'.$table.'`;';
                $lines[] = $create.';';
                $lines[] = '';
            } catch (Throwable $e) {
                $this->warn('Skip '.$table.': '.$e->getMessage());
            }
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
        $lines[] = '';
        $lines[] = '-- Keep-table allowlist at backup time:';
        foreach ($report['keep'] as $keep) {
            $lines[] = '-- KEEP '.$keep['table'].' rows='.$keep['rows'];
        }

        File::put($output, implode("\n", $lines));

        $this->info('Schema-only backup written:');
        $this->line($output);
        $this->line('Tables dumped: '.count($allTables));
        $this->newLine();
        $this->line('Optional Hostinger mysqldump (run locally; do not paste passwords into chat):');
        $this->line('  mysqldump --no-data --routines --triggers -h "$CA_REFERENCE_DB_HOST" -P "$CA_REFERENCE_DB_PORT" -u "$CA_REFERENCE_DB_USERNAME" -p "$CA_REFERENCE_DB_DATABASE" > ca_reference_schema_backup.sql');

        return self::SUCCESS;
    }
}
