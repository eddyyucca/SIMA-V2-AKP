<?php

namespace App\Support\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/** Di MariaDB `LIKE` tidak membedakan huruf besar/kecil; di PostgreSQL ya. Grammar ini menyamakan perilakunya (LIKE -> ILIKE). */
class CaseInsensitivePostgresGrammar extends PostgresGrammar
{
    protected function whereBasic(Builder $query, $where)
    {
        $op = strtolower(trim($where['operator']));
        if ($op === 'like' || $op === 'not like') {
            $where['operator'] = $op === 'like' ? 'ilike' : 'not ilike';
        }

        return parent::whereBasic($query, $where);
    }
}
