<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

class MigratePostgresToMysqlCommand extends Command
{
    protected $signature = 'crm:migrate-pg-to-mysql
        {--source=pgsql : Source PostgreSQL connection name}
        {--target=mysql_target : Target MySQL connection name}
        {--chunk=500 : Rows per insert batch}
        {--skip-schema : Skip running Laravel migrations on MySQL}
        {--verify-only : Only compare row counts between source and target}';

    protected $description = 'Migrate CRM data from PostgreSQL to MySQL without modifying the source database';

    /** @var array<string, int> */
    private array $rowCountReport = [];

    public function handle(): int
    {
        $source = (string) $this->option('source');
        $target = (string) $this->option('target');

        try {
            $this->info('PostgreSQL → MySQL migration');
            $this->line('Source connection: '.$source);
            $this->line('Target connection: '.$target);

            $this->assertConnection($source, 'pgsql');

            if (! $this->option('verify-only')) {
                $this->ensureTargetDatabaseExists($target);
            }

            $this->assertConnection($target, 'mysql');

            if ($this->option('verify-only')) {
                return $this->verifyRowCounts($source, $target) ? self::SUCCESS : self::FAILURE;
            }

            if (! $this->option('skip-schema')) {
                $this->runTargetMigrations($target);
            }

            $tables = $this->sourceTables($source);
            $this->info('Tables to migrate: '.count($tables));

            $targetPdo = DB::connection($target)->getPdo();
            $targetPdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $targetPdo->exec('SET UNIQUE_CHECKS=0');
            $targetPdo->exec('SET NAMES utf8mb4');

            foreach ($tables as $table) {
                $this->migrateTable($source, $target, $table, (int) $this->option('chunk'));
            }

            $targetPdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $targetPdo->exec('SET UNIQUE_CHECKS=1');

            $this->resetAutoIncrements($target, $tables);

            $ok = $this->verifyRowCounts($source, $target);
            $this->writeReport($source, $target, $ok);

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Migration failed: '.$e->getMessage());
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    private function assertConnection(string $name, string $expectedDriver): void
    {
        $driver = DB::connection($name)->getDriverName();
        if ($driver !== $expectedDriver) {
            throw new \RuntimeException("Connection [{$name}] must use driver [{$expectedDriver}], got [{$driver}].");
        }

        DB::connection($name)->getPdo();
        $this->line("✓ Connected to {$name} ({$driver})");
    }

    private function ensureTargetDatabaseExists(string $target): void
    {
        $config = config('database.connections.'.$target);
        $database = (string) ($config['database'] ?? '');
        if ($database === '') {
            throw new \RuntimeException('Target database name is empty.');
        }

        $admin = config('database.connections.mysql_admin');
        if (! is_array($admin)) {
            $this->warn('mysql_admin connection not configured; assuming target database already exists.');

            return;
        }

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $admin['host'], $admin['port']),
            (string) $admin['username'],
            (string) $admin['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $safeName = str_replace('`', '``', $database);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->info("✓ Ensured MySQL database exists: {$database}");

        DB::purge($target);
    }

    private function runTargetMigrations(string $target): void
    {
        $this->info('Running Laravel migrations on MySQL...');
        $this->call('migrate', [
            '--database' => $target,
            '--force' => true,
        ]);
    }

    /** @return list<string> */
    private function sourceTables(string $source): array
    {
        $rows = DB::connection($source)->select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename",
        );

        return array_map(static fn ($row) => (string) $row->tablename, $rows);
    }

    private function migrateTable(string $source, string $target, string $table, int $chunk): void
    {
        if (! Schema::connection($target)->hasTable($table)) {
            $this->warn("Skipping {$table}: not present on MySQL schema.");

            return;
        }

        $sourceCount = (int) DB::connection($source)->table($table)->count();
        DB::connection($target)->table($table)->truncate();
        $this->line("Migrating {$table} ({$sourceCount} rows)...");

        if ($sourceCount === 0) {
            $this->rowCountReport[$table] = 0;

            return;
        }

        $columns = Schema::connection($target)->getColumnListing($table);
        $inserted = 0;

        $orderColumn = Schema::connection($source)->hasColumn($table, 'id') ? 'id' : $columns[0];

        DB::connection($source)
            ->table($table)
            ->orderBy($orderColumn)
            ->chunk($chunk, function ($rows) use ($target, $table, $columns, &$inserted) {
                $payload = [];
                foreach ($rows as $row) {
                    $payload[] = $this->normalizeRow((array) $row, $columns);
                }

                if ($payload !== []) {
                    DB::connection($target)->table($table)->insert($payload);
                    $inserted += count($payload);
                }
            });

        $this->rowCountReport[$table] = $inserted;
        $this->line("  → inserted {$inserted}");
    }

    /** @param list<string> $columns */
    private function normalizeRow(array $row, array $columns): array
    {
        $normalized = [];
        foreach ($columns as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];
            if ($value === null) {
                $normalized[$column] = null;

                continue;
            }

            if (is_bool($value)) {
                $normalized[$column] = $value ? 1 : 0;

                continue;
            }

            if (is_string($value) && $value === 't') {
                $normalized[$column] = 1;

                continue;
            }

            if (is_string($value) && $value === 'f') {
                $normalized[$column] = 0;

                continue;
            }

            if (is_array($value) || is_object($value)) {
                $normalized[$column] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                continue;
            }

            $normalized[$column] = $value;
        }

        return $normalized;
    }

    /** @param list<string> $tables */
    private function resetAutoIncrements(string $target, array $tables): void
    {
        foreach ($tables as $table) {
            if (! Schema::connection($target)->hasColumn($table, 'id')) {
                continue;
            }

            $maxId = DB::connection($target)->table($table)->max('id');
            if ($maxId === null) {
                continue;
            }

            $next = ((int) $maxId) + 1;
            DB::connection($target)->statement("ALTER TABLE `{$table}` AUTO_INCREMENT = {$next}");
        }
    }

    private function verifyRowCounts(string $source, string $target): bool
    {
        $this->info('Verifying row counts...');
        $tables = $this->sourceTables($source);
        $mismatches = [];

        foreach ($tables as $table) {
            if (! Schema::connection($target)->hasTable($table)) {
                $mismatches[] = [$table, 'missing_on_mysql', 0, 0];

                continue;
            }

            $sourceCount = (int) DB::connection($source)->table($table)->count();
            $targetCount = (int) DB::connection($target)->table($table)->count();

            if ($sourceCount !== $targetCount) {
                $mismatches[] = [$table, 'count_mismatch', $sourceCount, $targetCount];
            }

            $this->line(sprintf('  %-35s pg=%6d mysql=%6d %s', $table, $sourceCount, $targetCount, $sourceCount === $targetCount ? 'OK' : 'MISMATCH'));
        }

        if ($mismatches !== []) {
            $this->error('Row count mismatches detected: '.count($mismatches));

            return false;
        }

        $this->info('✓ All table row counts match.');

        return true;
    }

    private function writeReport(string $source, string $target, bool $ok): void
    {
        $reportDir = storage_path('backups/postgresql');
        if (! is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }

        $reportPath = $reportDir.'/mysql_migration_report_'.date('Ymd_His').'.json';
        $payload = [
            'completed_at' => now()->toIso8601String(),
            'source_connection' => $source,
            'target_connection' => $target,
            'target_database' => config('database.connections.'.$target.'.database'),
            'verified' => $ok,
            'tables' => $this->rowCountReport,
            'backup_files' => [
                'full_dump' => storage_path('backups/postgresql/crm_project_full_20260708.dump'),
                'schema_sql' => storage_path('backups/postgresql/crm_project_schema_20260708.sql'),
                'data_sql' => storage_path('backups/postgresql/crm_project_data_20260708.sql'),
            ],
        ];

        file_put_contents($reportPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Migration report: '.$reportPath);
    }
}
