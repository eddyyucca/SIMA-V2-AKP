<?php

namespace App\Reports;

use App\Models\Asset;
use App\Services\DepreciationService;
use Illuminate\Support\Collection;

/** Isi ekspor/cetak daftar aset dari menu Aset (mengikuti pencarian, filter, dan baris terpilih di tabel). */
class AssetListReport
{
    /** @return array{0:string[],1:array[]} [judul kolom, baris]. $pretty = format cetak (Rupiah bertitik), selain itu angka murni untuk Excel/CSV. */
    public static function build(Collection $assets, bool $pretty = false): array
    {
        $assets->loadMissing(['category', 'employee.department', 'location', 'vendor']);
        $dep = app(DepreciationService::class);
        $date = fn ($d) => $d ? $d->format('d/m/Y') : '';
        $money = fn ($v) => $pretty ? number_format((float) $v, 0, ',', '.') : (float) $v;

        if ($pretty) {
            $head = ['Kode', 'Kategori', 'Merek / Model', 'Serial', 'Pemegang', 'Department', 'Lokasi', 'Status', 'Kondisi', 'Tgl Beli', 'Harga', 'Nilai Buku'];
            $rows = $assets->map(fn (Asset $a) => [
                $a->asset_tag, (string) $a->category?->name, trim("{$a->brand} {$a->model}"), (string) $a->serial_number, (string) $a->employee?->name,
                (string) $a->employee?->department?->name, (string) $a->location?->name, Asset::STATUSES[$a->status] ?? $a->status, Asset::CONDITIONS[$a->condition] ?? $a->condition,
                $date($a->purchase_date), $money($a->purchase_price), $money($dep->bookValue($a, now())),
            ])->values()->all();
            return [$head, $rows];
        }

        $head = ['Kode', 'Kategori', 'Merek', 'Model', 'Serial', 'Pemegang', 'Department', 'Lokasi', 'Status', 'Kondisi', 'Tgl Beli', 'Harga Perolehan', 'Nilai Buku', 'Garansi s/d', 'Vendor', 'No. PO/PR'];
        $rows = $assets->map(fn (Asset $a) => [
            $a->asset_tag, (string) $a->category?->name, (string) $a->brand, (string) $a->model, (string) $a->serial_number,
            (string) $a->employee?->name, (string) $a->employee?->department?->name, (string) $a->location?->name,
            Asset::STATUSES[$a->status] ?? $a->status, Asset::CONDITIONS[$a->condition] ?? $a->condition, $date($a->purchase_date),
            $money($a->purchase_price), $money($dep->bookValue($a, now())), $date($a->warranty_until), (string) $a->vendor?->name, (string) $a->po_pr_no,
        ])->values()->all();
        return [$head, $rows];
    }
}
