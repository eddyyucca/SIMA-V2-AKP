<?php

namespace App\Services;

use App\Mail\BastMail;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\Repair;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class AssetService
{
    /** Serah terima (checkout) ke karyawan: aset harus Tersedia. */
    public function checkout(Asset $asset, Employee $to, array $d = []): AssetAssignment
    {
        if (! $to->isActive()) {
            throw new InvalidArgumentException('Karyawan berstatus resigned tidak bisa menerima barang baru.');
        }

        return $this->record($asset, 'checkout', $d, function (Asset $a) use ($to, $d) {
            if (in_array($a->status, Asset::INACTIVE, true)) throw new InvalidArgumentException('Aset disposed / sudah dikembalikan ke vendor tidak bisa di-checkout.');
            if ($a->status !== 'available') throw new InvalidArgumentException('Aset tidak berstatus Tersedia (gunakan Mutasi atau Pengembalian).');
            $res = \App\Models\ArrivalPlanItem::with('plan')->where('asset_id', $a->id)->whereIn('status', \App\Models\ArrivalPlanItem::RESERVING)->first();
            if ($res && (int) ($d['reservation_plan_id'] ?? 0) !== (int) $res->arrival_plan_id) {
                throw new InvalidArgumentException("Aset dicadangkan untuk {$res->plan->name} (rencana {$res->plan->plan_no}, tiba {$res->plan->arrival_date->format('d/m/Y')}). Serahkan lewat Rencana Kedatangan atau lepaskan dulu dari rencana.");
            }
            return ['from' => null, 'to' => $to->id, 'status' => ! empty($d['borrowed']) ? 'borrowed' : 'deployed'];
        });
    }

    /** Pengembalian (checkin) ke gudang IT. */
    public function checkin(Asset $asset, array $d = []): AssetAssignment
    {
        return $this->record($asset, 'checkin', $d, function (Asset $a) {
            if (! $a->employee_id) throw new InvalidArgumentException('Aset tidak sedang dipegang siapa pun.');
            return ['from' => $a->employee_id, 'to' => null, 'status' => in_array($a->status, ['repair', 'lost'], true) ? $a->status : 'available'];
        });
    }

    /** Mutasi antar karyawan; menghasilkan BAST baru. */
    public function transfer(Asset $asset, Employee $to, array $d = []): AssetAssignment
    {
        if (! $to->isActive()) {
            throw new InvalidArgumentException('Karyawan berstatus resigned tidak bisa menerima barang baru.');
        }

        return $this->record($asset, 'transfer', $d, function (Asset $a) use ($to, $d) {
            if (in_array($a->status, Asset::INACTIVE, true)) throw new InvalidArgumentException('Aset disposed / sudah dikembalikan ke vendor tidak bisa dimutasi.');
            if (in_array($a->status, ['repair', 'lost'], true)) throw new InvalidArgumentException('Aset sedang diperbaiki/hilang; selesaikan dulu sebelum dimutasi.');
            if (! $a->employee_id) throw new InvalidArgumentException('Aset belum dipegang siapa pun; gunakan Serah Terima.');
            if ($a->employee_id === $to->id) throw new InvalidArgumentException('Penerima sama dengan pemegang saat ini.');
            return ['from' => $a->employee_id, 'to' => $to->id, 'status' => ! empty($d['borrowed']) ? 'borrowed' : 'deployed'];
        });
    }

    private function record(Asset $asset, string $type, array $d, \Closure $plan): AssetAssignment
    {
        $assignment = DB::transaction(function () use ($asset, $type, $d, $plan) {
            $a = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            $p = $plan($a);

            $row = AssetAssignment::create([
                'asset_id' => $a->id,
                'type' => $type,
                'from_employee_id' => $p['from'],
                'to_employee_id' => $p['to'],
                'location_id' => $d['location_id'] ?? $a->location_id,
                'condition' => $d['condition'] ?? $a->condition,
                'signature_receiver_path' => $this->saveSignature($d['signature_receiver'] ?? null, 'recv'),
                'signature_it_path' => $this->saveSignature($d['signature_it'] ?? null, 'it'),
                'bast_number' => $this->nextBastNumber($type),
                'notes' => $d['notes'] ?? null,
                'assigned_at' => $d['assigned_at'] ?? now(),
                'created_by' => $d['created_by'] ?? auth()->id(),
            ]);

            $a->employee_id = $p['to'];
            $a->status = $p['status'];
            $a->condition = $d['condition'] ?? $a->condition;
            if (! empty($d['location_id'])) $a->location_id = $d['location_id'];
            $a->save();
            $asset->setRawAttributes($a->getAttributes(), true);

            return $row;
        });

        $this->makeBast($assignment);
        $this->mailBast($assignment);

        return $assignment->fresh();
    }

    /** Kembalikan aset sewa ke vendor: harus tidak dipegang, tanpa perbaikan terbuka, dan data sudah di-wipe. */
    public function returnToVendor(Asset $asset, array $d): Asset
    {
        if (empty($d['data_wiped'])) throw new InvalidArgumentException('Wipe data wajib sebelum aset dikembalikan ke vendor.');
        return DB::transaction(function () use ($asset, $d) {
            $a = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($a->ownership !== 'rental') throw new InvalidArgumentException('Hanya aset sewa yang dikembalikan ke vendor (aset milik sendiri: gunakan disposal).');
            if ($a->status === 'returned') throw new InvalidArgumentException('Aset sudah dikembalikan ke vendor.');
            if ($a->employee_id) throw new InvalidArgumentException('Aset masih dipegang karyawan; lakukan Checkin dulu.');
            if (Repair::where('asset_id', $a->id)->where('status', '!=', 'done')->exists()) throw new InvalidArgumentException('Masih ada perbaikan yang belum selesai.');
            \App\Models\LicenseSeat::where('asset_id', $a->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $a->update([
                'status' => 'returned', 'status_before_repair' => null,
                'returned_at' => $d['returned_at'] ?? now()->toDateString(),
                'condition' => $d['condition'] ?? $a->condition, 'return_notes' => $d['notes'] ?? null,
            ]);
            return $a;
        });
    }

    /** Perpanjang masa sewa (opsional ubah biaya per bulan). */
    public function extendRental(Asset $asset, string $newEnd, ?float $monthly = null): Asset
    {
        return DB::transaction(function () use ($asset, $newEnd, $monthly) {
            $a = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($a->ownership !== 'rental') throw new InvalidArgumentException('Hanya aset sewa yang bisa diperpanjang.');
            if ($a->status === 'returned') throw new InvalidArgumentException('Aset sudah dikembalikan ke vendor.');
            $end = \Illuminate\Support\Carbon::parse($newEnd);
            if ($a->rental_end && $end->lte($a->rental_end)) throw new InvalidArgumentException('Tanggal berakhir baru harus setelah tanggal berakhir saat ini.');
            $a->rental_end = $end;
            if ($monthly !== null) $a->rental_monthly_cost = $monthly;
            $a->save();
            return $a;
        });
    }

    /**
     * Arsipkan PDF kontrak sewa (jenis bebas; awal atau pembaruan tahunan). Berkas lama tidak pernah ditimpa.
     * Bila 'update_rental_end' true, tanggal berakhir sewa aset ikut maju bila periode baru lebih panjang.
     */
    public function addRentalContract(Asset $asset, array $d): \App\Models\RentalContract
    {
        $path = (string) ($d['path'] ?? '');
        $disk = Storage::disk('local');
        if (! str_starts_with($path, 'rental-contracts/') || str_contains($path, '..') || ! $disk->exists($path)) {
            throw new InvalidArgumentException('File kontrak tidak ditemukan, unggah ulang.');
        }
        if (! str_starts_with((string) $disk->get($path), '%PDF')) {
            $disk->delete($path);
            throw new InvalidArgumentException('File bukan PDF yang valid.');
        }
        $label = \App\Models\RentalContract::cleanLabel($d['label'] ?? '');
        if ($label === '') throw new InvalidArgumentException('Jenis kontrak wajib diisi.');
        $start = \Illuminate\Support\Carbon::parse($d['start_date']);
        $end = \Illuminate\Support\Carbon::parse($d['end_date']);
        if ($end->lt($start)) throw new InvalidArgumentException('Tanggal berakhir sebelum tanggal mulai.');

        return DB::transaction(function () use ($asset, $d, $label, $start, $end, $path) {
            $a = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($a->ownership !== 'rental') throw new InvalidArgumentException('Kontrak hanya untuk aset sewa.');
            $row = \App\Models\RentalContract::create([
                'asset_id' => $a->id, 'label' => $label, 'kind' => $d['kind'] ?? 'initial',
                'contract_no' => filled($d['contract_no'] ?? null) ? mb_substr(trim($d['contract_no']), 0, 255) : null,
                'start_date' => $start, 'end_date' => $end, 'path' => $path,
                'original_name' => filled($d['original_name'] ?? null) ? mb_substr(trim(basename((string) $d['original_name'])), 0, 255) : null,
                'note' => filled($d['note'] ?? null) ? mb_substr(trim($d['note']), 0, 250) : null, 'uploaded_by' => auth()->id(),
            ]);
            if (! empty($d['update_rental_end'])) {
                if (! $a->rental_end || $end->gt($a->rental_end)) $a->rental_end = $end;
                if (! $a->rental_start) $a->rental_start = $start;
                if ($row->contract_no) $a->rental_contract_no = $row->contract_no;
                $a->save();
            }
            return $row;
        });
    }

    /** Alasan aset tidak boleh dihapus lewat tombol hapus massal; null = boleh. Aset yang punya riwayat/pemegang harus lewat alur Disposal/Pengembalian. */
    public function deleteBlocker(Asset $a): ?string
    {
        if ($a->trashed()) return 'sudah dihapus';
        if ($a->employee_id) return 'sedang dipegang ' . ($a->employee?->name ?? 'karyawan');
        if ($a->status !== 'available') return 'status ' . (Asset::STATUSES[$a->status] ?? $a->status) . ' (hanya aset Tersedia yang bisa dihapus)';
        if (\App\Models\ArrivalPlanItem::where('asset_id', $a->id)->whereIn('status', \App\Models\ArrivalPlanItem::RESERVING)->exists()) return 'sedang dicadangkan untuk rencana kedatangan';
        if ($a->repairs()->where('status', '!=', 'done')->exists()) return 'masih punya tiket perbaikan terbuka';
        if ($a->disposals()->where('status', 'pending')->exists()) return 'punya pengajuan disposal yang menunggu';
        return null;
    }

    /** Hapus (soft delete) banyak aset sekaligus; yang tidak memenuhi syarat dilewati dan dilaporkan. @return array{deleted:string[],skipped:array<string,string>} */
    public function deleteMany(\Illuminate\Support\Collection $assets): array
    {
        $out = ['deleted' => [], 'skipped' => []];
        foreach ($assets as $asset) {
            DB::transaction(function () use ($asset, &$out) {
                $a = Asset::withTrashed()->with('employee')->lockForUpdate()->find($asset->id);
                if (! $a) return;
                if ($why = $this->deleteBlocker($a)) { $out['skipped'][$a->asset_tag] = $why; return; }
                $a->delete();
                $out['deleted'][] = $a->asset_tag;
            });
        }
        return $out;
    }

    /** Checkin massal untuk offboarding: satu BAST pengembalian per aset. */
    public function bulkCheckin(Employee $employee, array $items, array $d = []): array
    {
        $out = [];
        foreach ($items as $assetId => $condition) {
            $asset = Asset::where('employee_id', $employee->id)->findOrFail($assetId);
            $out[] = $this->checkin($asset, ['condition' => $condition] + $d);
        }
        return $out;
    }

    public function reportDamage(Asset $asset, ?Employee $reporter, string $issue): Repair
    {
        return DB::transaction(function () use ($asset, $reporter, $issue) {
            $a = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            if (in_array($a->status, Asset::INACTIVE, true)) throw new InvalidArgumentException('Aset sudah disposed / dikembalikan ke vendor.');
            $repair = Repair::create([
                'asset_id' => $a->id, 'reported_by' => $reporter?->id, 'reported_at' => now(),
                'issue' => $issue, 'status' => 'reported',
            ]);
            if ($a->status !== 'repair') {
                $a->status_before_repair = $a->status;
                $a->status = 'repair';
                $a->save();
            }
            app(NotificationService::class)->newDamageReport($repair);
            return $repair;
        });
    }

    public function finishRepair(Repair $repair, array $d): Repair
    {
        return DB::transaction(function () use ($repair, $d) {
            $repair->update($d + ['status' => 'done', 'finished_at' => now()]);
            $a = Asset::query()->lockForUpdate()->find($repair->asset_id);
            $stillOpen = Repair::where('asset_id', $a->id)->where('status', '!=', 'done')->exists();
            if (! $stillOpen && $a->status === 'repair') {
                $a->status = $a->status_before_repair ?: ($a->employee_id ? 'deployed' : 'available');
                $a->status_before_repair = null;
                $a->save();
            }
            return $repair;
        });
    }

    private function nextBastNumber(string $type): string
    {
        $ym = now()->format('Y/m') . '/';
        $prefix = AssetAssignment::NUMBER_PREFIX . $ym;
        // urutan per bulan; nomor berformat lama (BAST/2026/11/0053, belum dikonversi) tetap dihitung agar tidak ada nomor ganda
        $last = AssetAssignment::where(fn ($q) => $q->where('bast_number', 'like', $prefix . '%')->orWhere('bast_number', 'like', AssetAssignment::LEGACY_NUMBER_PREFIX . $ym . '%'))
            ->lockForUpdate()->pluck('bast_number')->map(fn ($n) => (int) substr($n, -4))->max();
        return $prefix . str_pad((string) (($last ?? 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    private function saveSignature(?string $dataUrl, string $who): ?string
    {
        if (! $dataUrl || ! str_starts_with($dataUrl, 'data:image/png;base64,')) return null;
        $bin = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
        if ($bin === false || strlen($bin) > 5 * 1024 * 1024) return null;
        $path = 'signatures/' . now()->format('Ym') . '/' . $who . '-' . bin2hex(random_bytes(8)) . '.png';
        Storage::disk('local')->put($path, $bin);
        return $path;
    }

    /** Arsipkan PDF BAST bertanda tangan (hasil scan). Setiap unggahan disimpan permanen; tidak ada yang ditimpa atau dihapus. */
    public function attachSignedBast(Asset $asset, ?AssetAssignment $a, string $path, ?string $note = null): \App\Models\AssetBastFile
    {
        if ($a && (int) $a->asset_id !== (int) $asset->id) throw new \InvalidArgumentException('BAST ini bukan milik aset tersebut.');
        $disk = Storage::disk('local');
        if (! str_starts_with($path, 'bast-signed/') || str_contains($path, '..') || ! $disk->exists($path)) {
            throw new \InvalidArgumentException('File PDF tidak ditemukan, unggah ulang.');
        }
        if (! str_starts_with((string) $disk->get($path), '%PDF')) {
            $disk->delete($path);
            throw new \InvalidArgumentException('File bukan PDF yang valid.');
        }
        return \App\Models\AssetBastFile::create([
            'asset_id' => $asset->id, 'asset_assignment_id' => $a?->id, 'path' => $path,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 250) : null, 'uploaded_by' => auth()->id(),
        ]);
    }

    /** Formulir BAST kosong (untuk dicetak dan ditandatangani) dari data aset saat ini. Tidak membuat catatan serah terima. */
    public function bastFormPdf(Asset $asset, string $type, ?Employee $emp = null): string
    {
        if (! isset(AssetAssignment::TYPES[$type])) throw new \InvalidArgumentException('Jenis BAST tidak dikenal.');
        $holder = $asset->employee;
        $from = match ($type) { 'checkout' => null, 'transfer' => $holder, default => $holder ?: $emp };
        $to = $type === 'checkin' ? null : $emp;

        $a = new AssetAssignment([
            'asset_id' => $asset->id, 'type' => $type, 'condition' => $asset->condition, 'location_id' => $asset->location_id,
            'assigned_at' => now(), 'bast_number' => '........................................',
        ]);
        $a->setRelation('asset', $asset->loadMissing('category'));
        $a->setRelation('from', $from?->loadMissing('department'));
        $a->setRelation('to', $to?->loadMissing('department'));
        $a->setRelation('location', $asset->location);
        $a->setRelation('creator', auth()->user());
        return Pdf::loadView('pdf.bast', ['a' => $a, 'sigReceiver' => null, 'sigIt' => null, 'form' => true])->setPaper('a4')->output();
    }

    /** BAST aktif pemegang saat ini: BAST terakhir aset itu bila berupa serah terima / mutasi ke pemegang sekarang. */
    public function currentHolderBast(Asset $asset): ?AssetAssignment
    {
        if (! $asset->employee_id) return null;
        $a = AssetAssignment::where('asset_id', $asset->id)->orderByDesc('assigned_at')->orderByDesc('id')->first();
        return ($a && in_array($a->type, ['checkout', 'transfer'], true) && (int) $a->to_employee_id === (int) $asset->employee_id) ? $a : null;
    }

    /** Daftar kelengkapan (id:jumlah) pada waktu tertentu, untuk membandingkan isi BAST dengan keadaan sekarang. */
    public function equipmentKey(Asset $asset, ?\Illuminate\Support\Carbon $asOf = null): string
    {
        return $asset->installedComponents($asOf)->map(fn ($r) => $r['component']->id . ':' . (int) $r['qty'])->sort()->implode(',');
    }

    /** True bila kelengkapan pada BAST pemegang berbeda dari komponen yang terpasang sekarang. */
    public function equipmentOutdated(Asset $asset): bool
    {
        $a = $this->currentHolderBast($asset);
        return $a !== null && $this->equipmentKey($asset, $a->equipmentAsOf()) !== $this->equipmentKey($asset);
    }

    /**
     * Perbarui kelengkapan pada BAST pemegang saat ini setelah komponen dipasang / dilepas: PDF dibuat ulang dengan kelengkapan terbaru
     * (berkas lama dicadangkan di bast-backup/, tidak pernah dihapus). Aset tanpa pemegang tidak memiliki BAST aktif: kelengkapan akan
     * ikut pada BAST berikutnya. Scan BAST yang sudah ditandatangani tidak diubah.
     */
    public function refreshEquipment(Asset $asset): ?AssetAssignment
    {
        $a = $this->currentHolderBast($asset->fresh());
        if (! $a) return null;
        $disk = Storage::disk('local');
        if ($a->bast_pdf_path && $disk->exists($a->bast_pdf_path)) {
            $disk->copy($a->bast_pdf_path, 'bast-backup/' . pathinfo($a->bast_pdf_path, PATHINFO_FILENAME) . '-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.pdf');
        }
        $a->update(['equipment_updated_at' => now()]);
        $this->makeBast($a->fresh());
        return $a->fresh();
    }

    public function makeBast(AssetAssignment $a): void
    {
        $a->loadMissing(['asset.category', 'from.department', 'to.department', 'location', 'creator']);
        $sig = fn ($p) => $p && Storage::disk('local')->exists($p)
            ? 'data:image/png;base64,' . base64_encode(Storage::disk('local')->get($p)) : null;
        $pdf = Pdf::loadView('pdf.bast', [
            'a' => $a, 'sigReceiver' => $sig($a->signature_receiver_path), 'sigIt' => $sig($a->signature_it_path),
        ])->setPaper('a4');
        $path = 'bast/' . str_replace('/', '-', $a->bast_number) . '.pdf';
        Storage::disk('local')->put($path, $pdf->output());
        $a->update(['bast_pdf_path' => $path]);
    }

    private function mailBast(AssetAssignment $a): void
    {
        $emp = $a->to ?: $a->from;
        if ($emp && $emp->email) {
            try { Mail::to($emp->email)->queue(new BastMail($a)); } catch (\Throwable $e) { report($e); }
        }
        try { app(NotificationService::class)->bastIssued($a); } catch (\Throwable $e) { report($e); }
    }
}
