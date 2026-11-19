<?php

namespace App\Support;

/** HRIS terhubung = data karyawan, department, dan section TIDAK boleh diinput/diubah manual di aplikasi ini. */
class Hris
{
    public static function managed(): bool
    {
        return filled(config('services.hris.url')) && filled(config('services.hris.token'));
    }

    public const LOCKED_MESSAGE = 'Data karyawan, department, dan section dikelola HRIS. Ubah di HRIS; aplikasi ini mengikuti lewat sinkronisasi.';
}
