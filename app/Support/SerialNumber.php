<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\Component;
use Illuminate\Database\Eloquent\Model;

/** Aturan tunggal: satu serial number hanya boleh dimiliki satu barang (aset atau komponen, termasuk yang sudah dihapus). */
class SerialNumber
{
    /** Rapikan: spasi di ujung dibuang, kosong menjadi null. */
    public static function clean(mixed $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    /** Pemilik serial ini selain $except, mis. "aset AKP-IT-LPT-001 (Lenovo ThinkPad)"; null bila bebas. */
    public static function holder(?string $serial, ?Model $except = null): ?string
    {
        $serial = self::clean($serial);
        if ($serial === null) return null;
        // huruf besar/kecil dan spasi di ujung dianggap sama (eksplisit, agar berlaku di MariaDB maupun PostgreSQL)
        $skip = fn ($q, string $class) => $except instanceof $class && $except->exists ? $q->whereKeyNot($except->getKey()) : $q;

        $a = $skip(Asset::withTrashed()->whereRaw('lower(trim(serial_number)) = ?', [mb_strtolower($serial)]), Asset::class)->first();
        if ($a) return "aset {$a->asset_tag}" . (trim("{$a->brand} {$a->model}") ? ' (' . trim("{$a->brand} {$a->model}") . ')' : '') . ($a->trashed() ? ' [sudah dihapus]' : '');
        $c = $skip(Component::withTrashed()->whereRaw('lower(trim(serial_number)) = ?', [mb_strtolower($serial)]), Component::class)->first();
        if ($c) return "komponen {$c->name}" . ($c->trashed() ? ' [sudah dihapus]' : '');
        return null;
    }

    public static function message(string $serial, string $holder): string
    {
        return "Serial number '{$serial}' sudah dipakai oleh {$holder}. Serial number tidak boleh sama.";
    }
}
