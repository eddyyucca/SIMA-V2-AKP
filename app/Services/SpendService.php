<?php

namespace App\Services;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\License;
use App\Models\Repair;
use App\Models\StockReceipt;
use Illuminate\Support\Carbon;

/**
 * Belanja per tahun, dipisah CAPEX (belanja modal) dan OPEX (biaya operasional), per bulan.
 *
 *  CAPEX: pembelian aset milik sendiri · pembelian komponen (upgrade) · lisensi perpetual
 *  OPEX : sewa aset (dihitung per hari) · perbaikan · aksesori & barang habis pakai · langganan (subscription/domain/hosting/VPS)
 *
 * Aset disposed ikut dihitung (uangnya sudah keluar saat dibeli). Sewa dihitung sampai hari ini (bulan yang belum
 * berjalan tidak dianggap biaya), dan berhenti pada tanggal pengembalian bila aset sudah dikembalikan ke vendor.
 */
class SpendService
{
    public const LINES = [
        'assets'      => ['kind' => 'capex', 'label' => 'Pembelian aset'],
        'components'  => ['kind' => 'capex', 'label' => 'Komponen / upgrade'],
        'perpetual'   => ['kind' => 'capex', 'label' => 'Lisensi perpetual'],
        'rental'      => ['kind' => 'opex',  'label' => 'Sewa aset (rental)'],
        'repairs'     => ['kind' => 'opex',  'label' => 'Perbaikan'],
        'consumables' => ['kind' => 'opex',  'label' => 'Aksesori & barang habis pakai'],
        'subscription' => ['kind' => 'opex', 'label' => 'Langganan (domain, hosting, VPS, subscription)'],
    ];

    /** Tahun paling awal yang punya catatan biaya (untuk pilihan tahun); tahun ini bila belum ada data. */
    public function firstYear(): int
    {
        $dates = array_filter([
            Asset::where('ownership', 'owned')->whereNotNull('purchase_date')->min('purchase_date'),
            Asset::where('ownership', 'rental')->whereNotNull('rental_start')->min('rental_start'),
            StockReceipt::min('received_at'),
            Repair::where('cost', '>', 0)->whereNotNull('finished_at')->min('finished_at'),
            License::whereNotNull('purchase_date')->min('purchase_date'),
        ]);
        $min = $dates ? min(array_map(fn ($d) => (int) substr((string) $d, 0, 4), $dates)) : (int) now()->year;
        return max(2000, min($min, (int) now()->year));
    }

    /**
     * @return array{months:array<int,array{capex:float,opex:float}>,lines:array<string,array{kind:string,label:string,total:float,monthly:array<int,float>}>,capex:float,opex:float,total:float}
     */
    public function year(int $year): array
    {
        $lines = [];
        foreach (self::LINES as $k => $meta) $lines[$k] = $meta + ['total' => 0.0, 'monthly' => array_fill(1, 12, 0.0)];
        $add = function (string $k, int $month, float $v) use (&$lines) {
            $lines[$k]['monthly'][$month] += $v;
            $lines[$k]['total'] += $v;
        };
        $inYear = fn ($q, string $col) => $q->whereYear($col, $year);

        // CAPEX: aset milik sendiri (tanggal beli)
        foreach ($inYear(Asset::withTrashed()->where('ownership', 'owned')->whereNotNull('purchase_date'), 'purchase_date')->get(['purchase_date', 'purchase_price']) as $a) {
            $add('assets', (int) $a->purchase_date->format('n'), (float) $a->purchase_price);
        }

        // pembelian stok: komponen = CAPEX; aksesori & barang habis pakai = OPEX
        foreach ($inYear(StockReceipt::query(), 'received_at')->get(['stockable_type', 'received_at', 'qty', 'unit_price']) as $r) {
            $key = match ($r->stockable_type) {
                Component::class => 'components',
                Accessory::class, Consumable::class => 'consumables',
                default => null,
            };
            if ($key) $add($key, (int) $r->received_at->format('n'), (float) $r->qty * (float) $r->unit_price);
        }

        // lisensi: perpetual = CAPEX; lainnya = OPEX (tanggal beli, bila kosong tanggal dibuat)
        foreach (License::withTrashed()->get(['type', 'purchase_date', 'created_at', 'price']) as $l) {
            $date = $l->purchase_date ?? $l->created_at;
            if ($date && (int) $date->format('Y') === $year) $add($l->type === 'perpetual' ? 'perpetual' : 'subscription', (int) $date->format('n'), (float) $l->price);
        }

        // OPEX: perbaikan yang sudah selesai (tanggal selesai)
        foreach ($inYear(Repair::where('cost', '>', 0)->whereNotNull('finished_at'), 'finished_at')->get(['finished_at', 'cost']) as $r) {
            $add('repairs', (int) $r->finished_at->format('n'), (float) $r->cost);
        }

        // OPEX: sewa, biaya bulanan dibagi per hari menurut hari yang tercakup masa sewa pada bulan itu
        $today = now()->startOfDay();
        foreach (Asset::withTrashed()->where('ownership', 'rental')->whereNotNull('rental_start')->whereNotNull('rental_end')->get() as $a) {
            $from = $a->rental_start->copy()->startOfDay();
            $to = $a->rental_end->copy()->startOfDay();
            if ($a->returned_at && $a->returned_at->lt($to)) $to = $a->returned_at->copy()->startOfDay();
            $monthly = (float) $a->rental_monthly_cost;
            if ($monthly <= 0 || $to->lt($from)) continue;
            for ($m = 1; $m <= 12; $m++) {
                $ms = Carbon::create($year, $m, 1)->startOfDay();
                $me = $ms->copy()->endOfMonth()->startOfDay();
                $start = $from->gt($ms) ? $from : $ms;
                $end = collect([$to, $me, $today])->min();
                if ($end->lt($start)) continue;
                $days = $start->diffInDays($end) + 1;
                $add('rental', $m, round($monthly * $days / $ms->daysInMonth, 2));
            }
        }

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = [
                'capex' => round(collect($lines)->where('kind', 'capex')->sum(fn ($l) => $l['monthly'][$m]), 2),
                'opex' => round(collect($lines)->where('kind', 'opex')->sum(fn ($l) => $l['monthly'][$m]), 2),
            ];
        }
        $capex = round(collect($months)->sum('capex'), 2);
        $opex = round(collect($months)->sum('opex'), 2);
        return ['months' => $months, 'lines' => $lines, 'capex' => $capex, 'opex' => $opex, 'total' => round($capex + $opex, 2)];
    }
}
