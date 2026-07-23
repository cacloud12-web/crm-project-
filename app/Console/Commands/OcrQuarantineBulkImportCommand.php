<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrQuarantinedBulkImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Quarantined bulk import for OCR needs_review rows.
 * Default: dry-run counts only. Never FORCE ACCEPT ALL into Master.
 */
class OcrQuarantineBulkImportCommand extends Command
{
    protected $signature = 'ocr:quarantine-bulk-import
        {--dry-run : Classify and print counts only (default unless --apply)}
        {--apply : Copy all rows into quarantine; create Master only for otherwise_valid}
        {--chunk=500 : Chunk size}
        {--resume : Resume a prior batch}
        {--batch-id= : Explicit batch id (required with --resume)}
        {--document= : Limit to one OCR document}
        {--limit=0 : Stop after N rows (0 = all)}
        {--actor= : Actor user id for audits}
        {--with-backup : Before --apply, dump CRM (+ CA Reference if configured) into storage/app/backups}
        {--rollback : Roll back links created by --batch-id (does not delete ca_masters)}
        {--force : Required with --apply in production}';

    protected $description = 'Quarantine OCR needs_review rows; auto-import only otherwise_valid (never overwrite Master)';

    public function handle(OcrQuarantinedBulkImportService $service): int
    {
        if ((bool) $this->option('rollback')) {
            $batchId = trim((string) $this->option('batch-id'));
            if ($batchId === '') {
                $this->error('--rollback requires --batch-id=');

                return self::FAILURE;
            }
            try {
                $result = $service->rollback($batchId, $this->actorId());
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->table(['Metric', 'Value'], [
                ['batch_id', $result['batch_id']],
                ['unlinked', $result['unlinked']],
                ['candidates_marked', $result['candidates_marked']],
                ['master_created_ids', count($result['master_created_ids'])],
            ]);
            $this->warn('ca_masters created by this batch were NOT deleted. Review master_created_ids in batch summary before any purge.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply || (bool) $this->option('dry-run');
        if ((bool) $this->option('dry-run')) {
            $apply = false;
            $dryRun = true;
        }

        if ($apply && app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing production --apply without --force.');

            return self::FAILURE;
        }

        $backupPaths = null;
        if ($apply && (bool) $this->option('with-backup')) {
            try {
                $backupPaths = $this->runBackups();
            } catch (Throwable $e) {
                $this->error('Backup failed — aborting apply: '.$e->getMessage());

                return self::FAILURE;
            }
            $this->info('Backups written:');
            foreach ($backupPaths as $label => $path) {
                $this->line("  {$label}: {$path}");
            }
        }

        $mode = $dryRun ? 'DRY-RUN (counts only; no Master / quarantine writes)' : 'APPLY (quarantine all; Master create for otherwise_valid only)';
        $this->info('OCR quarantine bulk import — '.$mode);
        $this->warn('This command never force-accepts all needs_review rows into ca_masters.');

        try {
            $report = $service->run([
                'dry_run' => $dryRun,
                'apply' => $apply && ! $dryRun,
                'chunk' => (int) ($this->option('chunk') ?? 500),
                'resume' => (bool) $this->option('resume'),
                'batch_id' => $this->option('batch-id') ?: null,
                'actor' => $this->actorId(),
                'document' => $this->option('document') !== null && $this->option('document') !== ''
                    ? (int) $this->option('document')
                    : null,
                'limit' => (int) ($this->option('limit') ?? 0),
                'backup_paths' => $backupPaths,
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['batch_id', $report['batch_id'] ?? '—'],
            ['dry_run', ! empty($report['dry_run']) ? 'yes' : 'no'],
            ['scanned', $report['scanned'] ?? 0],
            ['eligible_for_master', $report['eligible_for_master'] ?? 0],
            ['quarantined', $report['quarantined'] ?? 0],
            ['imported (apply only)', $report['imported'] ?? 0],
            ['linked_existing (apply only)', $report['linked_existing'] ?? 0],
            ['errors', $report['errors'] ?? 0],
            ['missing_ca', $report['flags']['missing_ca'] ?? 0],
            ['missing_city', $report['flags']['missing_city'] ?? 0],
            ['address_as_ca', $report['flags']['address_as_ca'] ?? 0],
            ['conflicts', $report['flags']['conflicts'] ?? 0],
            ['duplicate', $report['flags']['duplicate'] ?? 0],
        ]);

        $this->newLine();
        $this->info('Category breakdown');
        $catRows = [];
        foreach (($report['by_category'] ?? []) as $cat => $n) {
            $catRows[] = [$cat, $n];
        }
        $this->table(['Category', 'Count'], $catRows);

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry-run complete. No Master writes. No quarantine candidate rows persisted.');
            $this->comment('Review counts above. If approved, run backups then apply with a NEW batch id:');
            $this->line('  ./php artisan ocr:quarantine-bulk-import --apply --with-backup --force --actor=ID --batch-id=qbi_apply_'.now()->format('Ymd_His'));
            $this->comment('Resume an interrupted apply:');
            $this->line('  ./php artisan ocr:quarantine-bulk-import --apply --resume --batch-id=EXISTING_BATCH --with-backup --force --actor=ID');
            $this->comment('Rollback OCR links from an apply batch (does not delete ca_masters):');
            $this->line('  ./php artisan ocr:quarantine-bulk-import --rollback --batch-id=EXISTING_BATCH --actor=ID');
            $this->warn('STOPPING for approval — do not apply until counts are reviewed.');
        }

        return self::SUCCESS;
    }

    private function actorId(): ?int
    {
        $raw = $this->option('actor');
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }

    /**
     * @return array<string, string>
     */
    private function runBackups(): array
    {
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Ymd_His');
        $paths = [];

        $crmPath = $dir.'/crm_backup_'.$stamp.'.sql';
        $paths['crm'] = $this->mysqldumpDefault($crmPath);

        $caHost = env('CA_REFERENCE_DB_HOST');
        $caDb = env('CA_REFERENCE_DB_DATABASE');
        if ($caHost && $caDb) {
            $caPath = $dir.'/ca_reference_backup_'.$stamp.'.sql';
            $paths['ca_reference'] = $this->mysqldumpConnection('ca_reference', $caPath);
        }

        return $paths;
    }

    private function mysqldumpDefault(string $outPath): string
    {
        $connection = config('database.default');
        if ($connection === 'sqlite') {
            $dbPath = config('database.connections.sqlite.database');
            $copy = preg_replace('/\.sql$/', '.sqlite', $outPath) ?: $outPath.'.sqlite';
            if (! is_string($dbPath) || ! is_file($dbPath)) {
                throw new \RuntimeException('SQLite database file not found for CRM backup.');
            }
            if (! copy($dbPath, $copy)) {
                throw new \RuntimeException('Failed to copy SQLite CRM database.');
            }

            return $copy;
        }

        return $this->mysqldumpConnection($connection, $outPath);
    }

    private function mysqldumpConnection(string $connection, string $outPath): string
    {
        $cfg = config('database.connections.'.$connection);
        if (! is_array($cfg)) {
            throw new \RuntimeException("Unknown DB connection: {$connection}");
        }
        $driver = $cfg['driver'] ?? '';
        if ($driver === 'sqlite') {
            $dbPath = $cfg['database'] ?? '';
            $copy = preg_replace('/\.sql$/', '.sqlite', $outPath) ?: $outPath.'.sqlite';
            if (! is_string($dbPath) || ! is_file($dbPath)) {
                throw new \RuntimeException("SQLite file missing for {$connection}");
            }
            if (! copy($dbPath, $copy)) {
                throw new \RuntimeException("Failed to copy {$connection} sqlite");
            }

            return $copy;
        }
        if ($driver !== 'mysql') {
            throw new \RuntimeException("Backup for driver {$driver} is not automated; dump {$connection} manually.");
        }

        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (string) ($cfg['port'] ?? '3306');
        $db = (string) ($cfg['database'] ?? '');
        $user = (string) ($cfg['username'] ?? '');
        $pass = (string) ($cfg['password'] ?? '');
        if ($db === '' || $user === '') {
            throw new \RuntimeException("Incomplete MySQL config for {$connection}");
        }

        $cmd = sprintf(
            'mysqldump --single-transaction --quick -h %s -P %s -u %s %s %s > %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            $pass !== '' ? '-p'.escapeshellarg($pass) : '',
            escapeshellarg($db),
            escapeshellarg($outPath),
        );
        // Avoid putting password in process list when possible via env MYSQL_PWD.
        $env = $pass !== '' ? 'MYSQL_PWD='.escapeshellarg($pass).' ' : '';
        $cmd = sprintf(
            '%smysqldump --single-transaction --quick -h %s -P %s -u %s %s > %s',
            $env,
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg($db),
            escapeshellarg($outPath),
        );
        exec($cmd.' 2>&1', $output, $code);
        if ($code !== 0 || ! is_file($outPath) || filesize($outPath) < 10) {
            throw new \RuntimeException('mysqldump failed for '.$connection.': '.implode("\n", $output));
        }

        return $outPath;
    }
}
