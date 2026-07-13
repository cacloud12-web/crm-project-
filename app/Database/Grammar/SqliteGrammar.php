<?php

namespace App\Database\Grammar;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar as BaseSqliteGrammar;

class SqliteGrammar extends BaseSqliteGrammar
{
    /**
     * Map PostgreSQL ILIKE to a case-insensitive LIKE comparison on SQLite.
     */
    protected function whereBasic(Builder $query, $where): string
    {
        if (isset($where['operator']) && strtolower((string) $where['operator']) === 'ilike') {
            $column = $this->wrap($where['column']);
            $value = $this->parameter($where['value']);

            return 'LOWER('.$column.') LIKE LOWER('.$value.')';
        }

        return parent::whereBasic($query, $where);
    }
}
