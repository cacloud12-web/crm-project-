<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

class SqlAggregate
{
    public static function countFilter(string $expression, string $condition): string
    {
        $condition = self::normalizeCondition($condition);

        if (DB::getDriverName() === 'pgsql') {
            return "COUNT({$expression}) FILTER (WHERE {$condition})";
        }

        return "SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END)";
    }

    public static function roundPercentOfTotal(string $condition, string $totalExpression = 'COUNT(*)'): string
    {
        $condition = self::normalizeCondition($condition);
        $numerator = self::countFilter('*', $condition);

        if (DB::getDriverName() === 'pgsql') {
            return "ROUND(({$numerator})::numeric / NULLIF({$totalExpression}, 0) * 100, 1)";
        }

        return "ROUND(({$numerator}) / NULLIF({$totalExpression}, 0) * 100, 1)";
    }

    private static function normalizeCondition(string $condition): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return $condition;
        }

        $condition = (string) preg_replace_callback(
            "/([`\"A-Za-z0-9_.]+)\s+ILIKE\s+'([^']*)'/i",
            static fn (array $matches) => 'LOWER('.$matches[1].") LIKE LOWER('".$matches[2]."')",
            $condition,
        );

        $condition = (string) preg_replace('/\s=\s*true\b/i', ' = 1', $condition);
        $condition = (string) preg_replace('/\s=\s*false\b/i', ' = 0', $condition);

        if (DB::getDriverName() === 'sqlite') {
            $condition = (string) preg_replace('/\btrue\b/i', '1', $condition);
            $condition = (string) preg_replace('/\bfalse\b/i', '0', $condition);
        }

        return $condition;
    }
}
