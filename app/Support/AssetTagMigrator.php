<?php

namespace App\Support;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merapikan kode aset ke format sekarang ({AWALAN}-{KATEGORI}-{URUT, jumlah digit tetap}). Aman dijalankan berulang.
 *  1. Kode yang sudah berformat benar tetapi nomornya kurang digit diberi nol di depan (AKP-IT-NB-001 -> AKP-IT-NB-0001), nomor tetap.
 *  2. Kode dengan kode kategori / awalan lama (mis. AKP-IT-LPT-001, LPT-2026-001) diberi nomor baru melanjutkan nomor tertinggi kategorinya,
 *     berurutan menurut id aset.
 * Setiap kode lama disimpan di riwayat (asset_tag_aliases) supaya label lama tetap bisa discan.
 */
class AssetTagMigrator
{
    /** Rencana perubahan tanpa menyimpan apa pun: [['id', 'old', 'new', 'pad' => bool]]. */
    public static function plan(?string $prefix = null, ?int $digits = null): array
    {
        $prefix = strtoupper(trim($prefix ?: (string) (DB::table('settings')->where('key', 'asset_tag_prefix')->value('value') ?: config('asset_tag.prefix'))));
        $digits ??= Asset::tagDigits();
        $rows = DB::table('assets')->leftJoin('categories', 'categories.id', '=', 'assets.category_id')
            ->orderBy('assets.id')->get(['assets.id', 'assets.asset_tag', 'categories.code_prefix']);
        $head = fn ($r) => $prefix . '-' . strtoupper($r->code_prefix ?: 'AST') . '-';
        $taken = $rows->pluck('asset_tag')->flip()->all();

        $plan = []; $counters = [];
        // 1) kode berformat benar: nomor tertinggi per kategori, dan tambahkan nol bila kurang digit
        foreach ($rows as $r) {
            $h = $head($r);
            if (! str_starts_with($r->asset_tag, $h) || ! preg_match('/^(\d+)$/', substr($r->asset_tag, strlen($h)), $m)) continue;
            $n = (int) $m[1];
            $counters[$h] = max($counters[$h] ?? 0, $n);
            $new = $h . str_pad((string) $n, $digits, '0', STR_PAD_LEFT);
            if ($new !== $r->asset_tag) {
                if (isset($taken[$new])) continue;                       // sudah dipakai aset lain: biarkan (tidak mungkin menabrak)
                $plan[$r->id] = ['id' => $r->id, 'old' => $r->asset_tag, 'new' => $new, 'pad' => true];
                unset($taken[$r->asset_tag]); $taken[$new] = true;
            }
        }
        // 2) kode lama: nomor baru melanjutkan nomor tertinggi
        foreach ($rows as $r) {
            $h = $head($r);
            if (str_starts_with($r->asset_tag, $h) && preg_match('/^\d+$/', substr($r->asset_tag, strlen($h)))) continue;
            do {
                $counters[$h] = ($counters[$h] ?? 0) + 1;
                $new = $h . str_pad((string) $counters[$h], $digits, '0', STR_PAD_LEFT);
            } while (isset($taken[$new]));
            $plan[$r->id] = ['id' => $r->id, 'old' => $r->asset_tag, 'new' => $new, 'pad' => false];
            unset($taken[$r->asset_tag]); $taken[$new] = true;
        }
        ksort($plan);
        return array_values($plan);
    }

    /** Jalankan rencana di satu transaksi. Mengembalikan jumlah aset yang berubah. */
    public static function renumber(?string $prefix = null, ?int $digits = null): int
    {
        $plan = static::plan($prefix, $digits);
        if (! $plan) return 0;
        DB::transaction(function () use ($plan) {
            $now = now();
            foreach ($plan as $p) {
                DB::table('assets')->where('id', $p['id'])->update(['asset_tag' => $p['new']]);
                if (Schema::hasTable('asset_tag_aliases')) {
                    DB::table('asset_tag_aliases')->insertOrIgnore(['asset_id' => $p['id'], 'tag' => $p['old'], 'created_at' => $now, 'updated_at' => $now]);
                }
                if (Schema::hasTable('activity_log')) {
                    DB::table('activity_log')->insert([
                        'log_name' => 'default', 'description' => 'updated', 'event' => 'updated', 'subject_type' => 'App\\Models\\Asset', 'subject_id' => $p['id'],
                        'properties' => json_encode(['attributes' => ['asset_tag' => $p['new']], 'old' => ['asset_tag' => $p['old']]]),
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });
        return count($plan);
    }
}
