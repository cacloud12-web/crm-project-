<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

class SqlDate
{
    public static function dateCast(string $column): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "{$column}::date";
        }

        return "DATE({$column})";
    }

    public static function monthLabel(string $column, string $alias = 'month'): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "TO_CHAR(DATE_TRUNC('month', {$column}), 'YYYY-MM') as {$alias}";
        }

        return "DATE_FORMAT({$column}, '%Y-%m') as {$alias}";
    }

    public static function monthBucket(string $column): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "DATE_TRUNC('month', {$column})";
        }

        return "DATE_FORMAT({$column}, '%Y-%m')";
    }

    public static function weekLabel(string $column, string $alias = 'label'): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "TO_CHAR(DATE_TRUNC('week', {$column}), 'YYYY-MM-DD') as {$alias}";
        }

        $monday = self::mysqlWeekStart($column);

        return "DATE_FORMAT({$monday}, '%Y-%m-%d') as {$alias}";
    }

    public static function weekBucket(string $column): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "DATE_TRUNC('week', {$column})";
        }

        return self::mysqlWeekStart($column);
    }

    public static function yearMonthLabel(string $column, string $alias = 'month'): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "TO_CHAR({$column}, 'YYYY-MM') as {$alias}";
        }

        return "DATE_FORMAT({$column}, '%Y-%m') as {$alias}";
    }

    public static function yearMonthBucket(string $column): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "TO_CHAR({$column}, 'YYYY-MM')";
        }

        return "DATE_FORMAT({$column}, '%Y-%m')";
    }

    private static function mysqlWeekStart(string $column): string
    {
        return "DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY)";
    }
}
