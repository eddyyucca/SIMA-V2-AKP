<?php

namespace App\Support\Database;

use Illuminate\Database\PostgresConnection;

class CaseInsensitivePostgresConnection extends PostgresConnection
{
    protected function getDefaultQueryGrammar()
    {
        return new CaseInsensitivePostgresGrammar($this);
    }
}
