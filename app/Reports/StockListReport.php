<?php

namespace App\Reports;

use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use Illuminate\Support\Collection;

/** Isi ekspor/cetak daftar Accessories, Komponen, dan Consumable (mengikuti pencarian dan filter tabel). */
class StockListReport
{
    public const TITLES = [Accessory::class => 'Daftar Accessories', Component::class => 'Daftar Komponen', Consumable::class => 'Daftar Consumable'];
    public const FILES = [Accessory::class => 'daftar-accessories', Component::class => 'daftar-komponen', Consumable::class => 'daftar-consumable'];

    /** @return array{0:string[],1:array[]} [judul kolom, baris]. $pretty = format cetak (Rupiah bertitik); selain itu angka murni untuk Excel/CSV. */
    public static function build(string $class, Collection $items, bool $pretty = false): array
    {
        $items->loadMissing(['category', 'location', 'vendor']);
        $rp = fn ($v) => $pretty ? number_format((float) $v, 0, ',', '.') : (float) $v;
        $num = fn ($v) => $pretty ? rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') : (float) $v;
        $isConsumable = $class === Consumable::class;
        $isComponent = $class === Component::class;

        $head = ['Kode', 'Nama', 'Kategori'];
        if ($isComponent) array_push($head, 'Serial', 'Spesifikasi');
        if ($isConsumable) array_push($head, 'Satuan', 'Kompatibel untuk');
        array_push($head, ...($isConsumable ? ['Stok', 'Minimum'] : ['Total', 'Tersedia', 'Terpakai / keluar', 'Minimum']));
        array_push($head, 'Status stok', 'Lokasi', 'Vendor terakhir', 'Harga terakhir (Rp)', 'Harga rata-rata (Rp)', 'Nilai stok (Rp)');

        $rows = $items->map(function ($i) use ($rp, $num, $isConsumable, $isComponent) {
            $row = [$i->code, $i->name, $i->category?->name ?? '-'];
            if ($isComponent) array_push($row, $i->serial_number ?: '-', $i->specs ?: '-');
            if ($isConsumable) array_push($row, $i->unit ?: '-', $i->compatible_with ?: '-');
            if ($isConsumable) {
                array_push($row, $num($i->qty), $num($i->min_qty));
            } else {
                array_push($row, (int) $i->qty_total, (int) $i->qty_available, (int) $i->qty_total - (int) $i->qty_available, (int) $i->min_qty);
            }
            $last = $isComponent ? $i->price : $i->unit_price;
            array_push($row, $i->isLow() ? 'Menipis' : 'Aman', $i->location?->name ?? '-', $i->vendor?->name ?? '-', $rp($last), $rp($i->average_price), $rp($i->stock_value));
            return $row;
        })->all();

        return [$head, $rows];
    }
}
