<?php

namespace App\Support;

use App\Models\Setting;

/** Awalan kode untuk Accessories, Consumable, dan Komponen (diatur di menu Pengaturan). */
class CodePrefix
{
    public const DEFAULTS = ['accessory' => 'ACC', 'consumable' => 'CSM', 'component' => 'CMP'];
    public const LABELS = ['accessory' => 'Accessories', 'consumable' => 'Consumable', 'component' => 'Komponen'];

    public static function get(string $kind): string
    {
        $v = strtoupper(trim((string) Setting::get('code_prefix_' . $kind, '')));
        return $v !== '' ? $v : self::DEFAULTS[$kind];
    }

    /** Semua awalan yang dikenali saat scan: yang aktif sekarang dan bawaan (label lama tetap terbaca). */
    public static function all(string $kind): array
    {
        return array_values(array_unique([self::get($kind), self::DEFAULTS[$kind]]));
    }
}
