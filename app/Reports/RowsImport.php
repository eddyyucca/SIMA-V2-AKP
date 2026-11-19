<?php

namespace App\Reports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/** Membaca baris CSV/XLSX (baris pertama = header) ke $rows. */
class RowsImport implements ToArray, WithHeadingRow
{
    public array $rows = [];

    public function array(array $array): void
    {
        $this->rows = $array;
    }
}
