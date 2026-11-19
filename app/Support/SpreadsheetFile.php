<?php

namespace App\Support;

use Maatwebsite\Excel\Excel;

/**
 * Menentukan pembaca file impor dari ISI file, bukan dari ekstensi atau tebakan jenis MIME.
 * File .xlsx dari Google Sheets / LibreOffice kadang terbaca sebagai "zip", dan nama file bisa saja salah ekstensi.
 */
class SpreadsheetFile
{
    public const EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    /** Jenis pembaca Excel untuk berkas di $path (xlsx = zip "PK", xls lama = OLE "D0CF11E0", selain itu CSV/teks). */
    public static function readerType(string $path): string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 8);
        if (str_starts_with($head, "PK\x03\x04")) return Excel::XLSX;
        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) return Excel::XLS;
        return Excel::CSV;
    }

    /** Aturan validasi ekstensi nama file yang diunggah (jenis sebenarnya ditentukan dari isi). */
    public static function extensionRule(): string
    {
        return 'extensions:' . implode(',', self::EXTENSIONS);
    }
}
