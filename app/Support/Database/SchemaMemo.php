<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\Schema;

/**
 * Request-lifetime memo for Schema::hasTable / hasColumn / getColumnListing.
 * Master Data listing was spending dozens of information_schema/pragma round-trips
 * per page because activity + projection called Schema helpers repeatedly.
 */
class SchemaMemo
{
    /** @var array<string, bool> */
    private static array $tables = [];

    /** @var array<string, bool> */
    private static array $columns = [];

    /** @var array<string, list<string>> */
    private static array $columnListings = [];

    public static function hasTable(string $table): bool
    {
        if (! array_key_exists($table, self::$tables)) {
            if (array_key_exists($table, self::$columnListings)) {
                self::$tables[$table] = self::$columnListings[$table] !== [];
            } else {
                self::$tables[$table] = Schema::hasTable($table);
            }
        }

        return self::$tables[$table];
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $key = $table.'.'.$column;
        if (! array_key_exists($key, self::$columns)) {
            // Avoid Schema::hasColumn — Laravel re-runs getColumnListing/pragma each call.
            self::$columns[$key] = in_array($column, self::columnListing($table), true);
        }

        return self::$columns[$key];
    }

    /**
     * @return list<string>
     */
    public static function columnListing(string $table): array
    {
        if (! array_key_exists($table, self::$columnListings)) {
            if (! self::hasTable($table)) {
                self::$columnListings[$table] = [];
            } else {
                self::$columnListings[$table] = Schema::getColumnListing($table);
            }
        }

        return self::$columnListings[$table];
    }

    /** @internal testing */
    public static function flush(): void
    {
        self::$tables = [];
        self::$columns = [];
        self::$columnListings = [];
    }
}
