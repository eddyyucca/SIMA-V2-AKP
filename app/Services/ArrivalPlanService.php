<?php

namespace App\Services;

use App\Models\Accessory;
use App\Models\ArrivalPlan;
use App\Models\ArrivalPlanItem;
use App\Models\Asset;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rencana kedatangan karyawan onsite.
 * Alur: Direncanakan -> Disiapkan -> Siap -> Diserahkan (atau Dibatalkan). Barang yang direncanakan DICADANGKAN
 * (tidak bisa diserahkan ke orang lain) sampai diserahkan atau rencana dibatalkan.
 */
class ArrivalPlanService
{
    private function assertEditable(ArrivalPlan $p): void
    {
        if (! $p->isActive()) {
            throw new InvalidArgumentException('Rencana sudah ' . strtolower(ArrivalPlan::STATUSES[$p->status]) . ' dan tidak bisa diubah.');
        }
    }

    public function nextNo(): string
    {
        $prefix = 'RK/' . now()->format('Y/m') . '/';
        $last = ArrivalPlan::where('plan_no', 'like', $prefix . '%')->lockForUpdate()->orderByDesc('plan_no')->value('plan_no');
        $n = $last ? ((int) substr($last, -4)) + 1 : 1;
        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $d): ArrivalPlan
    {
        if (trim((string) ($d['name'] ?? '')) === '') throw new InvalidArgumentException('Nama karyawan wajib diisi.');
        if (empty($d['arrival_date'])) throw new InvalidArgumentException('Tanggal tiba wajib diisi.');
        if (Carbon::parse($d['arrival_date'])->startOfDay()->lt(now()->startOfDay())) throw new InvalidArgumentException('Tanggal tiba tidak boleh di masa lalu.');
        $email = ! empty($d['email']) ? strtolower(trim($d['email'])) : null;
        if ($email) {
            if (! \App\Support\Hris::managed() && Employee::withTrashed()->where('email', $email)->exists()) throw new InvalidArgumentException('Email sudah dipakai karyawan yang terdaftar.');
            if (ArrivalPlan::active()->where('email', $email)->exists()) throw new InvalidArgumentException('Email sudah ada pada rencana kedatangan lain.');
        }

        $nik = isset($d['nik']) && trim((string) $d['nik']) !== '' ? trim((string) $d['nik']) : null;
        // HRIS terhubung: karyawan baru memang sudah ada di HRIS (dan tersinkron), jadi NIK terdaftar justru yang diharapkan.
        if (! \App\Support\Hris::managed() && $nik && Employee::withTrashed()->where('nik', $nik)->exists()) throw new InvalidArgumentException('NIK sudah dipakai karyawan yang terdaftar.');

        return DB::transaction(fn () => ArrivalPlan::create([
            'plan_no' => $this->nextNo(), 'nik' => $nik, 'name' => trim($d['name']), 'email' => $email, 'phone' => $d['phone'] ?? null,
            'department_id' => $d['department_id'] ?? null, 'position' => $d['position'] ?? null, 'location_id' => $d['location_id'] ?? null,
            'arrival_date' => Carbon::parse($d['arrival_date'])->toDateString(), 'status' => 'planned',
            'pic_id' => $d['pic_id'] ?? auth()->id(), 'notes' => $d['notes'] ?? null, 'created_by' => auth()->id(),
        ]));
    }

    /** Status rencana mengikuti status barangnya (selama masih berjalan). */
    public function recompute(ArrivalPlan $p): void
    {
        $p->refresh();
        if (! $p->isActive()) return;
        $s = $p->items()->whereIn('status', ArrivalPlanItem::RESERVING)->pluck('status');
        $status = match (true) {
            $s->isEmpty() => 'planned',
            $s->every(fn ($x) => $x === 'ready') => 'ready',
            $s->contains(fn ($x) => in_array($x, ['prepared', 'ready'], true)) => 'prepared',
            default => 'planned',
        };
        if ($p->status !== $status) $p->update(['status' => $status]);
    }

    public function addAsset(ArrivalPlan $plan, Asset $asset): ArrivalPlanItem
    {
        return DB::transaction(function () use ($plan, $asset) {
            $p = ArrivalPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($p);
            $a = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($a->status !== 'available' || $a->employee_id) {
                throw new InvalidArgumentException("Aset {$a->asset_tag} tidak tersedia (status: " . (Asset::STATUSES[$a->status] ?? $a->status) . ').');
            }
            $held = ArrivalPlanItem::with('plan')->where('asset_id', $a->id)->whereIn('status', ArrivalPlanItem::RESERVING)->first();
            if ($held) {
                throw new InvalidArgumentException("Aset {$a->asset_tag} sudah dicadangkan untuk {$held->plan->name} (rencana {$held->plan->plan_no}).");
            }
            $item = $p->items()->create(['type' => 'asset', 'asset_id' => $a->id, 'qty' => 1, 'status' => 'planned']);
            $this->recompute($p);
            return $item;
        });
    }

    /** Jumlah accessory yang sedang dicadangkan oleh seluruh rencana yang berjalan. */
    public function reservedQty(Accessory $acc): int
    {
        return (int) ArrivalPlanItem::where('accessory_id', $acc->id)->whereIn('status', ArrivalPlanItem::RESERVING)->sum('qty');
    }

    public function addAccessory(ArrivalPlan $plan, Accessory $accessory, int $qty): ArrivalPlanItem
    {
        if ($qty < 1) throw new InvalidArgumentException('Jumlah minimal 1.');
        return DB::transaction(function () use ($plan, $accessory, $qty) {
            $p = ArrivalPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($p);
            $c = Accessory::query()->lockForUpdate()->findOrFail($accessory->id);
            $free = $c->qty_available - $this->reservedQty($c);
            if ($qty > $free) {
                throw new InvalidArgumentException("Stok {$c->name} yang bisa dicadangkan hanya {$free} (tersedia {$c->qty_available}, sudah dicadangkan " . $this->reservedQty($c) . ').');
            }
            $item = $p->items()->whereIn('status', ArrivalPlanItem::RESERVING)->where('accessory_id', $c->id)->first();
            if ($item) {
                $item->update(['qty' => $item->qty + $qty, 'status' => 'planned', 'ready_at' => null]);     // jumlah berubah -> disiapkan ulang
            } else {
                $item = $p->items()->create(['type' => 'accessory', 'accessory_id' => $c->id, 'qty' => $qty, 'status' => 'planned']);
            }
            $this->recompute($p);
            return $item;
        });
    }

    public function setItemStatus(ArrivalPlanItem $item, string $status): ArrivalPlanItem
    {
        if (! in_array($status, ArrivalPlanItem::RESERVING, true)) throw new InvalidArgumentException('Status tidak valid.');
        return DB::transaction(function () use ($item, $status) {
            $it = ArrivalPlanItem::query()->lockForUpdate()->findOrFail($item->id);
            $p = ArrivalPlan::query()->lockForUpdate()->findOrFail($it->arrival_plan_id);
            $this->assertEditable($p);
            if (! $it->isReserving()) throw new InvalidArgumentException('Barang ini sudah tidak dicadangkan.');
            if ($status === 'ready' && ($msg = $it->problem())) throw new InvalidArgumentException($it->label() . ': ' . $msg . '. Tidak bisa ditandai Siap.');
            $it->update(['status' => $status, 'ready_at' => $status === 'ready' ? now() : null]);
            $this->recompute($p);
            return $it;
        });
    }

    public function removeItem(ArrivalPlanItem $item): void
    {
        DB::transaction(function () use ($item) {
            $it = ArrivalPlanItem::query()->lockForUpdate()->findOrFail($item->id);
            $p = ArrivalPlan::query()->lockForUpdate()->findOrFail($it->arrival_plan_id);
            $this->assertEditable($p);
            $it->delete();                                                       // pencadangan dilepas
            $this->recompute($p);
        });
    }

    public function reschedule(ArrivalPlan $plan, string $date): ArrivalPlan
    {
        $p = ArrivalPlan::query()->findOrFail($plan->id);
        $this->assertEditable($p);
        if (Carbon::parse($date)->startOfDay()->lt(now()->startOfDay())) throw new InvalidArgumentException('Tanggal tiba tidak boleh di masa lalu.');
        $p->update(['arrival_date' => Carbon::parse($date)->toDateString()]);
        return $p;
    }

    public function cancel(ArrivalPlan $plan, ?string $reason = null): ArrivalPlan
    {
        return DB::transaction(function () use ($plan, $reason) {
            $p = ArrivalPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($p);
            $p->items()->whereIn('status', ArrivalPlanItem::RESERVING)->update(['status' => 'cancelled']);   // semua pencadangan dilepas
            $p->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
            return $p;
        });
    }

    /**
     * Karyawan tiba: buat data karyawan, serahkan semua barang berstatus Siap (BAST per aset, stok accessories berkurang).
     * Jika ada yang belum siap, harus memilih "serahkan yang siap saja" (sisanya dilepas dari rencana).
     * Semua langkah dalam satu transaksi: jika satu gagal, tidak ada yang berubah.
     */
    public function handover(ArrivalPlan $plan, array $d): ArrivalPlan
    {
        return DB::transaction(function () use ($plan, $d) {
            $p = ArrivalPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($p);
            $items = $p->items()->whereIn('status', ArrivalPlanItem::RESERVING)->get();
            if ($items->isEmpty()) throw new InvalidArgumentException('Rencana belum memiliki barang.');
            $notReady = $items->where('status', '!=', 'ready');
            if ($notReady->isNotEmpty() && empty($d['partial'])) {
                throw new InvalidArgumentException("Masih ada {$notReady->count()} barang belum siap. Tandai Siap dulu, atau pilih \"serahkan yang siap saja\".");
            }
            $ready = $items->where('status', 'ready');
            if ($ready->isEmpty()) throw new InvalidArgumentException('Belum ada barang berstatus Siap.');

            if (\App\Support\Hris::managed()) {
                // HRIS terhubung: karyawan TIDAK dibuat manual. Harus sudah terdaftar di HRIS (dicari lewat NIK, lalu ditarik dari HRIS bila belum ada di sini).
                $nik = trim((string) $p->nik);
                if ($nik === '') throw new InvalidArgumentException('NIK wajib diisi: karyawan harus sudah terdaftar di HRIS.');
                $emp = Employee::withTrashed()->where('nik', $nik)->first();
                if (! $emp && ($h = app(\App\Services\Hris\EmployeeDirectory::class)->find($nik))) $emp = app(\App\Services\Hris\EmployeeSync::class)->upsert($h)['employee'];
                if (! $emp) throw new InvalidArgumentException("NIK {$nik} belum terdaftar di HRIS. Minta HR mendaftarkannya di HRIS, lalu coba lagi.");
                if ($emp->status !== 'active') throw new InvalidArgumentException("Karyawan {$emp->name} tidak aktif di HRIS.");
                $email = $emp->email;
            } else {
                $email = strtolower(trim((string) (($d['email'] ?? null) ?: $p->email)));
                if ($email === '') throw new InvalidArgumentException('Email karyawan wajib diisi untuk membuat data karyawan.');
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Format email tidak valid.');
                if (Employee::withTrashed()->where('email', $email)->exists()) throw new InvalidArgumentException('Email sudah terdaftar pada karyawan lain.');

                $nik = trim((string) $p->nik) !== '' ? trim((string) $p->nik) : null;
                if ($nik && Employee::withTrashed()->where('nik', $nik)->exists()) throw new InvalidArgumentException('NIK sudah terdaftar pada karyawan lain. Ubah NIK pada rencana dulu.');

                $emp = Employee::create([
                    'nik' => $nik, 'name' => $p->name, 'email' => $email, 'phone' => $p->phone, 'department_id' => $p->department_id,
                    'position' => $p->position, 'status' => 'active',
                ]);
            }

            foreach ($ready as $it) {
                if ($msg = $it->problem()) throw new InvalidArgumentException($it->label() . ': ' . $msg);
                if ($it->type === 'asset') {
                    $row = app(AssetService::class)->checkout($it->asset, $emp, [
                        'condition' => $d['condition'] ?? $it->asset->condition, 'location_id' => $p->location_id,
                        'notes' => "Rencana kedatangan {$p->plan_no}", 'signature_receiver' => $d['signature_receiver'] ?? null,
                        'signature_it' => $d['signature_it'] ?? null, 'reservation_plan_id' => $p->id,
                    ]);
                    $it->update(['status' => 'handed_over', 'asset_assignment_id' => $row->id]);
                }
            }
            // semua accessories dalam rencana ini diserahkan lewat satu BAST (stok berkurang di transaksi yang sama)
            $accItems = $ready->where('type', '!=', 'asset');
            if ($accItems->isNotEmpty()) {
                $h = app(AccessoryHandoverService::class)->checkout($emp, $accItems->map(fn ($it) => ['item' => 'accessory:' . $it->accessory_id, 'qty' => $it->qty])->all(),
                    null, "Rencana kedatangan {$p->plan_no}", ['type' => 'employee', 'from_plan' => true]);
                foreach ($accItems as $it) {
                    $mid = $h->items->firstWhere('accessory_id', $it->accessory_id)?->stock_movement_id;
                    $it->update(['status' => 'handed_over', 'stock_movement_id' => $mid]);
                }
            }
            foreach ($notReady as $it) $it->update(['status' => 'cancelled']);       // yang belum siap dilepas dari rencana

            $p->update(['status' => 'handed_over', 'employee_id' => $emp->id, 'handed_over_at' => now(), 'email' => $email]);
            return $p;
        });
    }
}
