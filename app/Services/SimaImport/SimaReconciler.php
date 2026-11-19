<?php

namespace App\Services\SimaImport;

use Illuminate\Support\Facades\DB;

/**
 * Rekonsiliasi SIMA vs aplikasi baru. Setiap baris: nilai SIMA, nilai baru, selisih, lulus/tidak.
 * Selisih yang memang terjelaskan (mis. kategori lisensi dilewati) dihitung di sisi harapan, bukan dibiarkan jadi "ok".
 */
class SimaReconciler
{
    /** @return list<array{check:string, legacy:mixed, new:mixed, ok:bool, note:string}> */
    public function run(SimaImporter $importer): array
    {
        $L = DB::connection('legacy');
        $map = fn (string $e) => DB::table('legacy_map')->where('entity', $e)->count();
        $rows = [];
        $add = function (string $check, $legacy, $new, string $note = '', ?bool $ok = null) use (&$rows) {
            $rows[] = ['check' => $check, 'legacy' => $legacy, 'new' => $new, 'ok' => $ok ?? ((string) $legacy === (string) $new), 'note' => $note];
        };

        $add('Aset (termasuk yang dihapus)', $L->table('assets')->count(), $map('asset'));
        $add('Aset: serial unik (tidak ada duplikat)', 0, (int) DB::table('assets')->whereNotNull('serial_number')->selectRaw('count(*) - count(distinct lower(serial_number)) as d')->value('d'));

        // status: hitung dari aturan yang sama, lalu bandingkan dengan isi tabel
        $labels = $L->table('status_labels')->get()->keyBy('id');
        $expect = [];
        foreach ($L->table('assets')->get(['status_id', 'assigned_type', 'assigned_to']) as $a) {
            $held = $a->assigned_type === 'App\\Models\\User' && $a->assigned_to;
            $st = $importer->mapStatus($labels[$a->status_id] ?? null, (bool) $held, false);
            $expect[$st] = ($expect[$st] ?? 0) + 1;
        }
        foreach (['available', 'deployed', 'repair', 'borrowed', 'lost', 'disposed', 'returned'] as $st) {
            $add("Aset berstatus {$st}", $expect[$st] ?? 0, DB::table('assets')->where('status', $st)->whereIn('id', DB::table('legacy_map')->where('entity', 'asset')->pluck('new_id'))->count(), 'tersisa tak dikenal dipetakan lewat config/sima_import.php');
        }

        $heldLegacy = $L->table('assets')->where('assigned_type', 'App\\Models\\User')->whereNotNull('assigned_to')->count();
        $add('Aset yang dipegang karyawan', $heldLegacy, DB::table('assets')->whereNotNull('employee_id')->whereIn('id', DB::table('legacy_map')->where('entity', 'asset')->pluck('new_id'))->count());
        $add('Total nilai pembelian aset', number_format((float) $L->table('assets')->sum('purchase_cost'), 2, '.', ''), number_format((float) DB::table('assets')->whereIn('id', DB::table('legacy_map')->where('entity', 'asset')->pluck('new_id'))->sum('purchase_price'), 2, '.', ''));

        // pemegang per karyawan
        $mism = 0;
        foreach ($L->table('assets')->where('assigned_type', 'App\\Models\\User')->whereNotNull('assigned_to')->selectRaw('assigned_to, count(*) c')->groupBy('assigned_to')->get() as $r) {
            $emp = DB::table('legacy_map')->where('entity', 'employee')->where('legacy_id', $r->assigned_to)->value('new_id');
            $have = $emp ? DB::table('assets')->where('employee_id', $emp)->count() : 0;
            if ($have !== (int) $r->c) $mism++;
        }
        $add('Karyawan dengan jumlah aset dipegang berbeda', 0, $mism);

        $add('Aset aktif tanpa riwayat serah terima sinkron', 0, (int) DB::table('assets')->whereNull('deleted_at')->whereNotNull('employee_id')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('asset_assignments')->whereColumn('asset_assignments.asset_id', 'assets.id'))->count());

        $acc = $L->table('accessories')->sum('qty');
        $add('Accessories: total stok', (int) $acc, (int) DB::table('accessories')->whereIn('id', DB::table('legacy_map')->where('entity', 'accessory')->pluck('new_id'))->sum('qty_total'));
        $add('Consumables: total stok', (int) $L->table('consumables')->sum('qty'), (int) DB::table('consumables')->whereIn('id', DB::table('legacy_map')->where('entity', 'consumable')->pluck('new_id'))->sum('qty'));
        $add('Komponen: stok tersedia', (int) ($L->table('components')->sum('qty') - $L->table('components_assets')->sum('assigned_qty')), (int) DB::table('components')->whereIn('id', DB::table('legacy_map')->where('entity', 'component')->pluck('new_id'))->sum('qty_available'));
        $add('Lisensi', $L->table('licenses')->count(), $map('license'));
        $add('Seat lisensi aktif', (int) $L->table('license_seats')->whereNull('deleted_at')->where(fn ($q) => $q->whereNotNull('assigned_to')->orWhereNotNull('asset_id'))->count(), (int) DB::table('license_seats')->whereIn('license_id', DB::table('legacy_map')->where('entity', 'license')->pluck('new_id'))->count());
        $add('Maintenance -> perbaikan', $L->table('maintenances')->count(), $map('repair'));

        $mapped = DB::table('legacy_map')->where('entity', 'asset')->pluck('new_id');
        $add('Token agent aktif', (int) $L->table('asset_agent_tokens')->whereNull('revoked_at')->count(), (int) DB::table('asset_agent_tokens')->whereNull('revoked_at')->whereIn('asset_id', $mapped)->count());
        $add('Heartbeat agent', $L->table('asset_heartbeats')->count(), DB::table('asset_heartbeats')->whereIn('asset_id', $mapped)->count());
        $add('Token helper agent', $L->table('asset_helper_tokens')->count(), DB::table('asset_helper_tokens')->whereIn('asset_id', $mapped)->count());

        // token tetap bisa dipakai: hash disalin persis (tidak dibuat ulang)
        $bad = 0;
        foreach ($L->table('asset_agent_tokens')->get(['id', 'token_hash', 'token_lookup', 'revoked_at']) as $t) {
            $new = DB::table('legacy_map')->where('entity', 'agent_token')->where('legacy_id', $t->id)->value('new_id');
            $row = $new ? DB::table('asset_agent_tokens')->where('id', $new)->first(['token_hash', 'token_lookup']) : null;
            if (! $row || $row->token_hash !== $t->token_hash || $row->token_lookup !== $t->token_lookup) $bad++;
        }
        $add('Token agent yang hash-nya berbeda/hilang', 0, $bad);

        $add('Tag aset unik', 0, (int) DB::table('assets')->selectRaw('count(*) - count(distinct asset_tag) as d')->value('d'));
        $add('Aset aktif tanpa kategori', 0, (int) DB::table('assets')->whereNull('category_id')->count());

        return $rows;
    }
}
