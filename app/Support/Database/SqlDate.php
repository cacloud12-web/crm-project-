<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

class SqlDate
{
    private static function driver(): string
    {
        return DB::getDriverName();
    }

    public static function dateCast(string $column): string
    {
        if (self::driver() === 'pgsql') {
            return "{$column}::date";
        }

        return "DATE({$column})";
    }

    public static function monthLabel(string $column, string $alias = 'month'): string
    {
        if (self::driver() === 'pgsql') {
            return "TO_CHAR(DATE_TRUNC('month', {$column}), 'YYYY-MM') as {$alias}";
        }

        if (self::driver() === 'sqlite') {
            return "strftime('%Y-%m', {$column}) as {$alias}";
        }

        return "DATE_FORMAT({$column}, '%Y-%m') as {$alias}";
    }

    public static function monthBucket(string $column): string
    {
        if (self::driver() === 'pgsql') {
            return "DATE_TRUNC('month', {$column})";
        }

        if (self::driver() === 'sqlite') {
            return "strftime('%Y-%m', {$column})";
        }

        return "DATE_FORMAT({$column}, '%Y-%m')";
    }

    public static function weekLabel(string $column, string $alias = 'label'): string
    {
        if (self::driver() === 'pgsql') {
            return "TO_CHAR(DATE_TRUNC('week', {$column}), 'YYYY-MM-DD') as {$alias}";
        }

        if (self::driver() === 'sqlite') {
            $start = self::sqliteWeekStart($column);

            return "strftime('%Y-%m-%d', {$start}) as {$alias}";
        }

        $monday = self::mysqlWeekStart($column);

        return "{$monday} as {$alias}";
    }

    public static function weekBucket(string $column): string
    {
        if (self::driver() === 'pgsql') {
            return "DATE_TRUNC('week', {$column})";
        }

        if (self::driver() === 'sqlite') {
            return self::sqliteWeekStart($column);
        }

        return self::mysqlWeekStart($column);
    }

    public static function yearMonthLabel(string $column, string $alias = 'month'): string
    {
        return self::monthLabel($column, $alias);
    }

    public static function yearMonthBucket(string $column): string
    {
        return self::monthBucket($column);
    }

    private static function mysqlWeekStart(string $column): string
    {
        return "DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY)";
    }

    /** Monday-start week bucket for SQLite (local dev). */
    private static function sqliteWeekStart(string $column): string
    {
        return "date({$column}, '-' || ((cast(strftime('%w', {$column}) as integer) + 6) % 7) || ' days')";
    }
}
