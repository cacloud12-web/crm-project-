<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\Schema;

class MigrationIndexHelper
{
    public static function exists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            $result = $connection->select(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index],
            );

            return count($result) > 0;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $result = $connection->select(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?
                 LIMIT 1',
                [$connection->getDatabaseName(), $table, $index],
            );

            return count($result) > 0;
        }

        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list('{$table}')");

            return collect($rows)->contains(fn ($row) => ($row->name ?? null) === $index);
        }

        return false;
    }
}
