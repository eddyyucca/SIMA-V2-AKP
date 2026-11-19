<?php

namespace App\Reports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\IValueBinder;

/**
 * Teks selalu ditulis sebagai teks (NIK, serial, telepon yang berisi angka tidak berubah jadi angka: nol di depan
 * dan angka panjang tetap utuh). Angka sungguhan (int/float, mis. harga) tetap menjadi angka.
 */
class TextExport extends DefaultValueBinder implements FromArray, WithHeadings, ShouldAutoSize, WithCustomValueBinder, IValueBinder
{
    public function __construct(private array $head, private array $rows) {}
    public function array(): array { return $this->rows; }
    public function headings(): array { return $this->head; }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);
            return true;
        }
        return parent::bindValue($cell, $value);
    }
}
