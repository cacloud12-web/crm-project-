<?php

namespace App\Services\CaReference;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Classifies ca_reference tables for accidental CRM cleanup.
 * Never prints credentials.
 */
class CaReferenceTableClassifier
{
    public const CONNECTION = 'ca_reference';

    /**
     * @return list<string>
     */
    public function keepTables(): array
    {
        $keep = array_values(array_unique(array_map('strval', config('ca_reference.keep_tables', []))));
        sort($keep);

        return $keep;
    }

    /**
     * @return list<string>
     */
    public function keepMigrations(): array
    {
        return array_values(array_unique(array_map('strval', config('ca_reference.keep_migrations', []))));
    }

    public function isKeepTable(string $table): bool
    {
        if (in_array($table, $this->keepTables(), true)) {
            return true;
        }

        foreach (config('ca_reference.keep_table_prefixes', []) as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function isKeepMigration(string $migration): bool
    {
        return in_array($migration, $this->keepMigrations(), true);
    }

    /**
     * @return array{
     *   database: string,
     *   keep: list<array{table: string, rows: int}>,
     *   drop: list<array{table: string, rows: int}>,
     *   unclassified_nonempty: list<array{table: string, rows: int}>,
     *   nonempty_drop: list<array{table: string, rows: int}>,
     *   accidental_migrations: list<array{id: int, migration: string, batch: int}>,
     *   keep_migration_rows: list<array{id: int, migration: string, batch: int}>
     * }
     */
    public function inspect(): array
    {
        $connection = DB::connection(self::CONNECTION);
        try {
            $connection->getPdo();
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to connect to ca_reference. Check CA_REFERENCE_DB_* without exposing secrets.');
        }

        $database = (string) $connection->getDatabaseName();
        $tables = $this->listTables($connection, $database);

        $keep = [];
        $drop = [];
        $unclassifiedNonEmpty = [];

        foreach ($tables as $table) {
            $rows = $this->safeCount($connection, $table);
            $entry = ['table' => $table, 'rows' => $rows];

            if ($this->isKeepTable($table)) {
                $keep[] = $entry;
                continue;
            }

            // Everything outside the allowlist is accidental CRM schema on this connection.
            $drop[] = $entry;
        }

        $nonemptyDrop = array_values(array_filter($drop, static fn (array $t) => $t['rows'] > 0));

        $migrationRows = [];
        if (Schema::connection(self::CONNECTION)->hasTable('migrations')) {
            $migrationRows = $connection->table('migrations')
                ->orderBy('id')
                ->get(['id', 'migration', 'batch'])
                ->map(static fn ($row) => [
                    'id' => (int) $row->id,
                    'migration' => (string) $row->migration,
                    'batch' => (int) $row->batch,
                ])
                ->all();
        }

        $keepMigrationRows = [];
        $accidentalMigrations = [];
        foreach ($migrationRows as $row) {
            if ($this->isKeepMigration($row['migration'])) {
                $keepMigrationRows[] = $row;
            } else {
                $accidentalMigrations[] = $row;
            }
        }

        return [
            'database' => $database,
            'keep' => $keep,
            'drop' => $drop,
            'unclassified_nonempty' => $unclassifiedNonEmpty,
            'nonempty_drop' => $nonemptyDrop,
            'accidental_migrations' => $accidentalMigrations,
            'keep_migration_rows' => $keepMigrationRows,
        ];
    }

    /**
     * @param  \Illuminate\Database\Connection  $connection
     * @return list<string>
     */
    private function listTables($connection, string $database): array
    {
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            $rows = $connection->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

            return array_values(array_map(static fn ($r) => (string) $r->name, $rows));
        }

        $rows = $connection->select('SHOW TABLES');
        $key = 'Tables_in_'.$database;
        $tables = [];
        foreach ($rows as $row) {
            $tables[] = (string) ($row->$key ?? array_values((array) $row)[0] ?? '');
        }
        $tables = array_values(array_filter($tables, static fn ($t) => $t !== ''));
        sort($tables);

        return $tables;
    }

    /**
     * @param  \Illuminate\Database\Connection  $connection
     */
    private function safeCount($connection, string $table): int
    {
        try {
            return (int) $connection->table($table)->count();
        } catch (Throwable) {
            return -1;
        }
    }
}
