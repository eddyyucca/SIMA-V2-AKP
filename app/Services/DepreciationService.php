<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Penyusutan garis lurus per bulan.
 *   per bulan = (harga perolehan − nilai residu) ÷ (umur × 12)
 * Bulan pertama = bulan tanggal beli (penuh); berhenti setelah bulan pelepasan (disposal/hilang) atau saat umur habis.
 * Akumulasi dihitung dari proporsi bulan (bukan dijumlahkan per bulan), sehingga total selalu pas tanpa selisih pembulatan.
 */
class DepreciationService
{
    /** Parameter perhitungan satu aset. */
    public function profile(Asset $a): array
    {
        $a->loadMissing('category');
        $cost = round((float) $a->purchase_price, 2);
        $reason = match (true) {
            $a->ownership === 'rental' => 'rental',
            ! $a->purchase_date || $cost <= 0 => 'no_cost',
            $cost < (float) config('depreciation.threshold') => 'expensed',
            default => null,
        };
        $years = (int) ($a->useful_life_years ?: $a->category?->useful_life_years ?: \App\Models\Category::defaultLifeYears());
        $pct = (float) ($a->residual_percent ?? $a->category?->residual_percent ?? config('depreciation.default_residual_percent'));
        $residual = round($cost * $pct / 100, 2);
        $base = max(0.0, round($cost - $residual, 2));
        $months = max(1, $years * 12);
        $start = $a->purchase_date?->copy()->startOfMonth();
        return [
            'depreciable' => $reason === null, 'reason' => $reason, 'cost' => $cost, 'years' => $years, 'life_months' => $months,
            'residual_percent' => $pct, 'residual' => $residual, 'base' => $base,
            'per_month' => round($base / $months, 2), 'per_year' => round($base / max(1, $years), 2),
            'start' => $start, 'end' => $start?->copy()->addMonths($months - 1)->endOfMonth(),
        ];
    }

    /** Jumlah bulan yang sudah disusutkan sampai $asOf (maksimum = umur). */
    public function months(Asset $a, Carbon $asOf): int
    {
        $p = $this->profile($a);
        if (! $p['depreciable']) return 0;
        $stop = $a->retired_at && $a->retired_at->lt($asOf) ? $a->retired_at : $asOf;
        $m = ($stop->year - $p['start']->year) * 12 + ($stop->month - $p['start']->month) + 1;
        return max(0, min($p['life_months'], $m));
    }

    public function accumulated(Asset $a, Carbon $asOf): float
    {
        $p = $this->profile($a);
        return $p['depreciable'] ? round($p['base'] * $this->months($a, $asOf) / $p['life_months'], 2) : 0.0;
    }

    /** Nilai buku pada posisi tanggal: 0 jika belum diperoleh, sudah dilepas/hilang, dibebankan, atau sewa. */
    public function bookValue(Asset $a, Carbon $asOf): float
    {
        $p = $this->profile($a);
        if (! $p['depreciable'] || $asOf->lt($p['start'])) return 0.0;
        if ($a->retired_at && $asOf->gt($a->retired_at)) return 0.0;
        return round($p['cost'] - $this->accumulated($a, $asOf), 2);
    }

    /** Nilai buku tepat pada tanggal pelepasan (untuk laba/rugi); null jika belum dilepas. */
    public function bookValueAtRetirement(Asset $a): ?float
    {
        $p = $this->profile($a);
        if (! $a->retired_at || ! $p['depreciable']) return $a->retired_at ? 0.0 : null;
        return round($p['cost'] - $this->accumulated($a, $a->retired_at->copy()), 2);
    }

    /** Laba (+) / rugi (−) pelepasan = harga jual − nilai buku saat dilepas. */
    public function gainLoss(Asset $a, float $salePrice): ?float
    {
        $book = $this->bookValueAtRetirement($a);
        return $book === null ? null : round($salePrice - $book, 2);
    }

    /** Jadwal penyusutan per tahun kalender (tahun pertama & terakhir parsial). */
    public function schedule(Asset $a): array
    {
        $p = $this->profile($a);
        if (! $p['depreciable']) return [];
        $last = $p['start']->copy()->addMonths($p['life_months'] - 1);
        $stop = $a->retired_at && $a->retired_at->lt($last) ? $a->retired_at->copy()->startOfMonth() : $last;
        $rows = [];
        $prev = 0.0;
        for ($y = $p['start']->year; $y <= $stop->year; $y++) {
            $asOf = Carbon::create($y, 12, 31);
            $acc = $this->accumulated($a, $asOf);
            $rows[] = [
                'year' => $y, 'months' => $this->months($a, $asOf) - $this->months($a, Carbon::create($y - 1, 12, 31)),
                'expense' => round($acc - $prev, 2), 'accumulated' => $acc, 'book_value' => round($p['cost'] - $acc, 2),
            ];
            $prev = $acc;
        }
        return $rows;
    }

    /** Penyusutan yang dibebankan pada tahun tertentu. */
    public function expenseInYear(Asset $a, int $year): float
    {
        return round($this->accumulated($a, Carbon::create($year, 12, 31)) - $this->accumulated($a, Carbon::create($year - 1, 12, 31)), 2);
    }

    public function statusLabel(Asset $a, Carbon $asOf): string
    {
        $p = $this->profile($a);
        return match (true) {
            $p['reason'] === 'rental' => 'Sewa (tidak disusutkan)',
            $p['reason'] === 'no_cost' => 'Tanpa harga/tanggal beli',
            $p['reason'] === 'expensed' => 'Dibebankan (di bawah ambang)',
            $asOf->lt($p['start']) => 'Belum diperoleh',
            $a->retired_at && $asOf->gt($a->retired_at) => $a->status === 'lost' ? 'Hilang' : 'Dilepas',
            $this->months($a, $asOf) >= $p['life_months'] => 'Habis disusutkan',
            default => 'Berjalan',
        };
    }

    /** Apakah aset termasuk yang "dimiliki" pada posisi tanggal (sudah dibeli, belum dilepas, disusutkan). */
    public function isHeld(Asset $a, Carbon $asOf): bool
    {
        $p = $this->profile($a);
        return $p['depreciable'] && $asOf->gte($p['start']) && ! ($a->retired_at && $asOf->gt($a->retired_at));
    }

    /** Total seluruh aset milik sendiri pada posisi tanggal. */
    public function totals(Carbon $asOf): array
    {
        $t = ['count' => 0, 'cost' => 0.0, 'accumulated' => 0.0, 'book' => 0.0, 'year_expense' => 0.0];
        foreach (Asset::with('category')->where('ownership', 'owned')->get() as $a) {
            $t['year_expense'] += $this->expenseInYear($a, $asOf->year);
            if (! $this->isHeld($a, $asOf)) continue;
            $acc = $this->accumulated($a, $asOf);
            $t['count']++;
            $t['cost'] += $this->profile($a)['cost'];
            $t['accumulated'] += $acc;
            $t['book'] += $this->bookValue($a, $asOf);
        }
        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $t);
    }

    /** Baris laporan per aset untuk satu tahun (posisi 31 Desember). */
    public function yearRow(Asset $a, int $year): array
    {
        $asOf = Carbon::create($year, 12, 31);
        $p = $this->profile($a);
        return [
            'profile' => $p, 'held' => $this->isHeld($a, $asOf),
            'accumulated' => $this->accumulated($a, $asOf), 'expense' => $this->expenseInYear($a, $year),
            'book' => $this->bookValue($a, $asOf), 'status' => $this->statusLabel($a, $asOf),
        ];
    }

    /** Penyusutan per kategori per tahun (untuk laporan ringkasan). */
    public function byCategoryByYear(Collection $assets): array
    {
        $out = [];
        $years = [];
        foreach ($assets as $a) {
            $name = $a->category?->name ?? 'Tanpa kategori';
            $p = $this->profile($a);
            $out[$name]['count'] = ($out[$name]['count'] ?? 0) + 1;
            $out[$name]['cost'] = ($out[$name]['cost'] ?? 0) + ($p['depreciable'] ? $p['cost'] : 0);
            foreach ($this->schedule($a) as $row) {
                $out[$name]['years'][$row['year']] = ($out[$name]['years'][$row['year']] ?? 0) + $row['expense'];
                $years[$row['year']] = true;
            }
        }
        ksort($out);
        $years = array_keys($years);
        sort($years);
        return ['rows' => $out, 'years' => $years];
    }
}
