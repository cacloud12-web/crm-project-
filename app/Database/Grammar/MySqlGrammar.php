<?php

namespace App\Database\Grammar;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar as BaseMySqlGrammar;

class MySqlGrammar extends BaseMySqlGrammar
{
    /**
     * Map PostgreSQL ILIKE to a case-insensitive LIKE comparison on MySQL.
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
