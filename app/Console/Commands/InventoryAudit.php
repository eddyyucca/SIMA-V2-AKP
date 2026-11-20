<?php

namespace App\Console\Commands;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Disposal;
use App\Models\Employee;
use App\Models\License;
use App\Models\Repair;
use App\Models\StockOpname;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** QC integritas data: cache stok vs pergerakan, pemegang vs riwayat, file dokumen, status antar-modul. */
class InventoryAudit extends Command
{
    protected $signature = 'inventory:audit';
    protected $description = 'Periksa konsistensi data antar fitur (stok, serah terima, lisensi, perbaikan, disposal, dokumen)';

    private array $fail = [];
    private array $info = [];

    public function handle(): int
    {
        $this->stock();
        $this->receipts();
        $this->depreciation();
        $this->arrivals();
        $this->assets();
        $this->licenses();
        $this->repairs();
        $this->disposals();
        $this->opnames();
        $this->files();

        foreach ($this->info as $i) $this->line("  [info] {$i}");
        if ($this->fail) {
            foreach ($this->fail as $f) $this->error("  [GAGAL] {$f}");
            $this->error(count($this->fail) . ' pelanggaran ditemukan.');
            return self::FAILURE;
        }
        $this->info('Audit lulus: semua invarian terpenuhi.');
        return self::SUCCESS;
    }

    private function check(bool $ok, string $msg): void { if (! $ok) $this->fail[] = $msg; }

    private function stock(): void
    {
        foreach (Accessory::withTrashed()->get() as $a) {
            $m = $a->movements;
            $in = $m->whereIn('type', ['in', 'adjust'])->sum('qty');
            $held = $m->where('type', 'out')->sum('qty') - $m->where('type', 'return')->sum('qty');
            $this->check((int) $a->qty_total === (int) $in, "Accessory '{$a->name}': total {$a->qty_total} != masuk {$in}");
            $this->check((int) $a->qty_available === (int) ($in - $held), "Accessory '{$a->name}': tersedia {$a->qty_available} != " . ($in - $held));
            $this->check($a->qty_available >= 0 && $a->qty_available <= $a->qty_total, "Accessory '{$a->name}': tersedia di luar rentang");
        }
        foreach (Consumable::withTrashed()->get() as $c) {
            $m = $c->movements;
            $exp = $m->whereIn('type', ['in', 'return', 'adjust'])->sum('qty') - $m->where('type', 'out')->sum('qty');
            $this->check(abs((float) $c->qty - (float) $exp) < 0.001, "Consumable '{$c->name}': stok {$c->qty} != {$exp}");
            $this->check((float) $c->qty >= 0, "Consumable '{$c->name}': stok negatif");
        }
        foreach (Component::withTrashed()->get() as $c) {
            $m = $c->movements;
            $in = $m->whereIn('type', ['in', 'adjust'])->sum('qty');
            $inst = $m->where('type', 'install')->sum('qty') - $m->where('type', 'uninstall')->sum('qty');
            $this->check((int) $c->qty_total === (int) $in, "Komponen '{$c->name}': total {$c->qty_total} != masuk {$in}");
            $this->check((int) $c->qty_available === (int) ($in - $inst), "Komponen '{$c->name}': tersedia {$c->qty_available} != " . ($in - $inst));
        }
    }

    /** Setiap stok masuk punya tepat satu pembelian; harga & vendor terakhir pada barang = pembelian terbaru. */
    private function receipts(): void
    {
        foreach ([Accessory::class, Consumable::class, Component::class] as $cls) {
            foreach ($cls::withTrashed()->get() as $i) {
                // saldo awal / riwayat hasil impor SIMA bukan pembelian di sistem ini: tidak punya catatan pembelian
                foreach ($i->movements()->where('type', 'in')->where(fn ($q) => $q->whereNull('reason')->orWhere('reason', 'not like', '%(impor SIMA)'))->get() as $m) {
                    $r = \App\Models\StockReceipt::where('stock_movement_id', $m->id)->get();
                    $this->check($r->count() === 1, "{$i->name}: pergerakan masuk #{$m->id} tidak punya tepat satu catatan pembelian");
                    if ($r->count() === 1) {
                        $this->check(abs((float) $r[0]->qty - (float) $m->qty) < 0.001, "{$i->name}: jumlah pembelian #{$r[0]->id} berbeda dengan riwayat stok");
                    }
                }
                $last = $i->latestReceipt();
                if ($last) {
                    $this->check(abs((float) $i->{$i->priceColumn()} - (float) $last->unit_price) < 0.01, "{$i->name}: harga terakhir tidak sama dengan pembelian terbaru");
                    if ($last->vendor_id) $this->check((int) $i->vendor_id === (int) $last->vendor_id, "{$i->name}: vendor terakhir tidak sama dengan pembelian terbaru");
                }
            }
        }
    }

    /** Rencana kedatangan: pencadangan unik, status rencana mengikuti barang, serah terima lengkap. */
    private function arrivals(): void
    {
        $reserving = \App\Models\ArrivalPlanItem::with('plan')->whereIn('status', \App\Models\ArrivalPlanItem::RESERVING)->get();
        foreach ($reserving->where('type', 'asset')->groupBy('asset_id') as $assetId => $rows) {
            $this->check($rows->count() === 1, "Aset #{$assetId} dicadangkan oleh lebih dari satu rencana");
        }
        foreach ($reserving as $it) {
            $this->check($it->plan && $it->plan->isActive(), "Barang rencana #{$it->id} masih dicadangkan padahal rencananya sudah selesai/dibatalkan");
        }
        foreach (Accessory::all() as $c) {
            $res = (int) $reserving->where('accessory_id', $c->id)->sum('qty');
            if ($res > $c->qty_available) $this->info[] = "Accessory '{$c->name}': dicadangkan {$res} melebihi stok tersedia {$c->qty_available}.";
        }
        foreach (\App\Models\ArrivalPlan::active()->with('items')->get() as $p) {
            $s = $p->items->whereIn('status', \App\Models\ArrivalPlanItem::RESERVING)->pluck('status');
            $exp = match (true) {
                $s->isEmpty() => 'planned',
                $s->every(fn ($x) => $x === 'ready') => 'ready',
                $s->contains(fn ($x) => in_array($x, ['prepared', 'ready'], true)) => 'prepared',
                default => 'planned',
            };
            $this->check($p->status === $exp, "Rencana {$p->plan_no}: status {$p->status} tidak sesuai status barangnya ({$exp})");
        }
        foreach (\App\Models\ArrivalPlan::where('status', 'handed_over')->with('items')->get() as $p) {
            $this->check($p->employee_id !== null && $p->handed_over_at !== null, "Rencana {$p->plan_no}: diserahkan tanpa data karyawan/tanggal");
            $this->check(! $p->items->contains(fn ($i) => in_array($i->status, \App\Models\ArrivalPlanItem::RESERVING, true)), "Rencana {$p->plan_no}: sudah diserahkan tapi masih ada barang dicadangkan");
            foreach ($p->items->where('status', 'handed_over') as $i) {
                $this->check($i->type === 'asset' ? $i->asset_assignment_id !== null : $i->stock_movement_id !== null, "Rencana {$p->plan_no}: barang #{$i->id} diserahkan tanpa BAST/pergerakan stok");
            }
        }
    }

    /** Penyusutan: total jadwal = akumulasi akhir; nilai buku tidak di bawah residu; aset dilepas punya tanggal pelepasan. */
    private function depreciation(): void
    {
        $dep = app(\App\Services\DepreciationService::class);
        foreach (Asset::withTrashed()->with('category')->where('ownership', 'owned')->get() as $a) {
            if (in_array($a->status, ['disposed', 'lost'], true)) {
                $this->check($a->retired_at !== null, "Aset {$a->asset_tag}: berstatus {$a->status} tanpa tanggal pelepasan");
            }
            if ($a->retired_at && $a->purchase_date) {
                $this->check($a->retired_at->gte($a->purchase_date->copy()->startOfMonth()), "Aset {$a->asset_tag}: tanggal pelepasan sebelum tanggal beli");
            }
            $p = $dep->profile($a);
            if (! $p['depreciable']) continue;
            $schedule = $dep->schedule($a);
            $sum = round(array_sum(array_column($schedule, 'expense')), 2);
            $far = \Illuminate\Support\Carbon::create(2200, 12, 31);
            $this->check(abs($sum - $dep->accumulated($a, $far)) < 0.02, "Aset {$a->asset_tag}: jumlah jadwal penyusutan ({$sum}) tidak sama dengan akumulasi akhir");
            $this->check($dep->accumulated($a, $far) <= $p['base'] + 0.01, "Aset {$a->asset_tag}: akumulasi melebihi nilai yang dapat disusutkan");
            if (! $a->retired_at) {
                $this->check(abs($dep->bookValue($a, $far) - $p['residual']) < 0.02, "Aset {$a->asset_tag}: nilai buku akhir tidak sama dengan nilai residu");
            }
        }
    }

    private function assets(): void
    {
        $this->check(Asset::withTrashed()->count() === Asset::withTrashed()->distinct('asset_tag')->count('asset_tag'), 'Kode aset tidak unik');
        $this->check(\Illuminate\Support\Facades\DB::table('asset_tag_aliases as al')->join('assets as a', 'a.asset_tag', '=', 'al.tag')->whereColumn('a.id', '!=', 'al.asset_id')->count() === 0,
            'Ada kode aset yang sama dengan kode lama (riwayat) aset lain');
        $this->check(AssetAssignment::count() === AssetAssignment::distinct('bast_number')->count('bast_number'), 'Nomor BAST tidak unik');
        $this->check(\App\Models\Brand::withTrashed()->count() === \App\Models\Brand::withTrashed()->get()->map(fn ($b) => mb_strtolower($b->name))->unique()->count(), 'Nama merek duplikat pada master');
        foreach (Asset::withTrashed()->with(['brandRef', 'modelRef'])->get() as $a) {
            if (filled($a->brand)) {
                $this->check($a->brand_id !== null && $a->brandRef && $a->brand === $a->brandRef->name, "Aset {$a->asset_tag}: merek '{$a->brand}' tidak sinkron dengan master");
            }
            if (filled($a->model)) {
                $this->check($a->asset_model_id !== null && $a->modelRef && $a->model === $a->modelRef->name && (int) $a->modelRef->brand_id === (int) $a->brand_id, "Aset {$a->asset_tag}: model '{$a->model}' tidak sinkron dengan master");
            }
            $last = $a->assignments()->first(); // terbaru
            $expected = $last?->to_employee_id;
            if ($last || $a->employee_id) {
                $this->check($a->employee_id === $expected, "Aset {$a->asset_tag}: pemegang ({$a->employee_id}) != riwayat terakhir ({$expected})");
            }
            if ($a->employee_id) {
                $this->check(in_array($a->status, ['deployed', 'borrowed', 'repair', 'lost'], true), "Aset {$a->asset_tag}: dipegang tapi status {$a->status}");
            } else {
                $this->check(in_array($a->status, ['available', 'repair', 'lost', 'disposed', 'returned'], true), "Aset {$a->asset_tag}: tanpa pemegang tapi status {$a->status}");
            }
        }
        foreach (Asset::withTrashed()->where('ownership', 'rental')->get() as $a) {
            $this->check($a->rental_start && $a->rental_end, "Aset sewa {$a->asset_tag}: tanggal kontrak belum lengkap");
            $this->check(! $a->rental_start || ! $a->rental_end || $a->rental_end->gte($a->rental_start), "Aset sewa {$a->asset_tag}: tanggal berakhir sebelum tanggal mulai");
            if ($a->status === 'returned') {
                $this->check($a->returned_at !== null && $a->employee_id === null, "Aset sewa {$a->asset_tag}: dikembalikan tapi tanggal kosong atau masih dipegang");
            } elseif ($a->rental_end && $a->rental_end->lt(now()->startOfDay())) {
                $this->info[] = "Sewa {$a->asset_tag} lewat jatuh tempo ({$a->rental_end->format('d/m/Y')}) dan belum dikembalikan — memicu peringatan.";
            }
        }
        foreach (Asset::where('status', 'returned')->where('ownership', '!=', 'rental')->get() as $a) {
            $this->check(false, "Aset milik sendiri {$a->asset_tag} berstatus dikembalikan ke vendor");
        }
        foreach (Employee::where('status', 'resigned')->get() as $e) {
            if ($e->holdsItems()) $this->info[] = "Karyawan resign '{$e->name}' masih memegang barang (sesuai skenario, ditandai di laporan).";
        }
    }

    private function licenses(): void
    {
        foreach (License::withTrashed()->get() as $l) {
            if ($l->seats_used > $l->seats) $this->info[] = "Lisensi '{$l->name}': seat melebihi ({$l->seats_used}/{$l->seats}) — memicu peringatan.";
            foreach ($l->activeSeats()->with('employee')->get() as $s) {
                $this->check(! ($s->employee && $s->employee->status === 'resigned'), "Lisensi '{$l->name}': seat aktif dipegang karyawan resign {$s->employee?->name}");
                $this->check((bool) ($s->employee_id || $s->asset_id), "Lisensi '{$l->name}': seat tanpa pemegang");
            }
        }
    }

    private function repairs(): void
    {
        foreach (Repair::where('status', '!=', 'done')->with('asset')->get() as $r) {
            $this->check($r->asset && in_array($r->asset->status, ['repair', 'disposed', 'lost', 'returned'], true), "Perbaikan #{$r->id} terbuka tapi aset {$r->asset?->asset_tag} berstatus {$r->asset?->status}");
        }
        foreach (Asset::where('status', 'repair')->get() as $a) {
            $this->check(Repair::where('asset_id', $a->id)->where('status', '!=', 'done')->exists(), "Aset {$a->asset_tag} status Perbaikan tanpa tiket terbuka");
        }
        foreach (Repair::where('status', 'done')->get() as $r) {
            $this->check($r->finished_at !== null && $r->action, "Perbaikan #{$r->id} selesai tanpa tindakan/tanggal");
        }
    }

    private function disposals(): void
    {
        foreach (Disposal::with('asset')->get() as $d) {
            if ($d->status === 'approved') {
                $this->check($d->asset->status === 'disposed', "Disposal #{$d->id} disetujui tapi aset {$d->asset->asset_tag} bukan disposed");
                $this->check((bool) $d->method && (bool) $d->ba_pdf_path && $d->data_wiped, "Disposal #{$d->id} disetujui tanpa metode/BA/wipe");
            }
            if ($d->status === 'pending') {
                $this->check($d->data_wiped, "Disposal #{$d->id} pending tanpa wipe");
            }
        }
        foreach (Asset::where('status', 'disposed')->get() as $a) {
            $this->check(Disposal::where('asset_id', $a->id)->where('status', 'approved')->exists(), "Aset {$a->asset_tag} disposed tanpa persetujuan");
            $this->check($a->employee_id === null, "Aset disposed {$a->asset_tag} masih ada pemegang");
        }
    }

    private function opnames(): void
    {
        foreach (StockOpname::where('status', 'finished')->get() as $o) {
            $this->check($o->items()->count() > 0, "Opname #{$o->id} selesai tanpa satu pun item");
            $this->check($o->finished_at !== null, "Opname #{$o->id} selesai tanpa waktu selesai");
        }
    }

    private function files(): void
    {
        $local = Storage::disk('local');
        $public = Storage::disk('public');
        foreach (\App\Models\AssetBastFile::with('asset')->get() as $f) {
            $this->check($local->exists($f->path), "Arsip BAST #{$f->id} ({$f->asset?->asset_tag}): file PDF unggahan hilang");
        }
        foreach (AssetAssignment::all() as $r) {
            $this->check($r->bast_pdf_path && $local->exists($r->bast_pdf_path), "BAST {$r->bast_number}: file PDF tidak ada");
            foreach (['signature_receiver_path', 'signature_it_path'] as $k) {
                if ($r->$k) $this->check($local->exists($r->$k), "BAST {$r->bast_number}: file tanda tangan {$k} hilang");
            }
        }
        foreach (Asset::withTrashed()->get() as $a) {
            if ($a->photo_path) $this->check($public->exists($a->photo_path), "Aset {$a->asset_tag}: foto hilang");
            foreach ($a->documents ?? [] as $d) $this->check($public->exists($d), "Aset {$a->asset_tag}: dokumen {$d} hilang");
        }
        foreach (Disposal::where('status', 'approved')->get() as $d) {
            $this->check($d->ba_pdf_path && $local->exists($d->ba_pdf_path), "Disposal #{$d->id}: Berita Acara hilang");
        }
    }
}
