<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\RequestItemLink;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pemrosesan request ITERA oleh tim IT: pemohon mengisi manual, lalu IT mencocokkan dengan database IT Asset
 * (karyawan dan barang), menyesuaikan datanya, dan memproses (BAST serah terima / peminjaman aset).
 */
class RequestProcessingService
{
    public function __construct(private AssetService $assets) {}

    /** Karyawan yang paling mungkin: yang sudah ditautkan, akun login yang sama, NIK sama, lalu nama sama. */
    public function suggestEmployee(ITRequest $r): ?Employee
    {
        if ($r->employee_id) return Employee::withTrashed()->find($r->employee_id);
        return ($r->user_id ? Employee::where('user_id', $r->user_id)->first() : null)
            ?? (filled($r->nik) ? Employee::where('nik', $r->nik)->first() : null)
            ?? (filled($r->requester_name) ? Employee::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($r->requester_name))])->first() : null);
    }

    /**
     * Simpan penyesuaian IT: data pemohon (opsional), karyawan tertaut, dan daftar barang database.
     * $links = [['kind' => 'asset|accessory|consumable|component', 'item_id' => 1, 'qty' => 1, 'note' => '...']].
     * Barang yang sudah diproses (aset sudah dipinjamkan) tidak bisa dihapus / diganti.
     */
    public function save(ITRequest $r, array $fields, ?int $employeeId, array $links): ITRequest
    {
        if ($employeeId && ! Employee::whereKey($employeeId)->exists()) throw new InvalidArgumentException('Karyawan yang dipilih tidak ditemukan.');

        $clean = [];
        foreach ($links as $l) {
            $kind = (string) ($l['kind'] ?? '');
            $id = (int) ($l['item_id'] ?? 0);
            if ($kind === '' && ! $id) continue;                         // baris kosong dilewati
            $class = RequestItemLink::CLASSES[$kind] ?? null;
            if (! $class) throw new InvalidArgumentException('Pilih jenis barang pada setiap baris.');
            $item = $id ? $class::find($id) : null;
            if (! $item) throw new InvalidArgumentException('Pilih barang pada setiap baris (' . (RequestItemLink::KINDS[$kind]) . ').');
            $qty = $l['qty'] ?? 1;
            if (! is_numeric($qty) || (float) $qty <= 0) throw new InvalidArgumentException('Jumlah harus lebih dari 0.');
            if ($kind !== 'consumable' && (float) $qty != (int) $qty) throw new InvalidArgumentException('Jumlah ' . RequestItemLink::KINDS[$kind] . ' harus bilangan bulat.');
            if ($kind === 'asset') {
                if (in_array($item->status, Asset::INACTIVE, true)) throw new InvalidArgumentException("Aset {$item->asset_tag} sudah disposed / dikembalikan ke vendor.");
                $qty = 1;
            }
            $k = "$kind:$id";
            if (isset($clean[$k])) throw new InvalidArgumentException('Barang yang sama dipilih lebih dari sekali: ' . ($kind === 'asset' ? $item->asset_tag : $item->name) . '.');
            $clean[$k] = ['kind' => $kind, 'item_id' => $id, 'qty' => round((float) $qty, 2), 'note' => filled($l['note'] ?? null) ? mb_substr(trim($l['note']), 0, 250) : null];
        }

        return DB::transaction(function () use ($r, $fields, $employeeId, $clean) {
            $r = ITRequest::query()->lockForUpdate()->findOrFail($r->id);
            $done = $r->links()->whereNotNull('processed_at')->get()->keyBy(fn ($x) => $x->kind . ':' . $x->item_id);
            foreach ($done as $k => $x) {
                if (! isset($clean[$k])) throw new InvalidArgumentException('Barang ' . $x->label() . ' sudah diproses (dipinjamkan) dan tidak bisa dihapus. Tandai request ini sudah kembali dulu.');
            }
            $r->fill(array_intersect_key($fields, array_flip(['requester_name', 'nik', 'department', 'position', 'title', 'description', 'loan_start_date', 'loan_end_date'])));
            $r->employee_id = $employeeId;
            $r->save();
            $r->links()->whereNull('processed_at')->delete();
            foreach ($clean as $k => $c) {
                if (isset($done[$k])) { $done[$k]->update(['qty' => $c['qty'], 'note' => $c['note']]); continue; }
                $r->links()->create($c + ['created_by' => auth()->id()]);
            }
            activity()->performedOn($r)->causedBy(auth()->user())->withProperties(['employee_id' => $employeeId, 'links' => array_values($clean)])->log('Request dicocokkan dengan data IT Asset');
            return $r->load(['links', 'employee']);
        });
    }

    /** Peminjaman Aset: aset yang ditautkan otomatis dipinjamkan ke karyawan tertaut (status Dipinjam, BAST dibuat). Mengembalikan jumlah aset yang diproses. */
    public function borrow(ITRequest $r): int
    {
        $r = $r->fresh();
        if ($r->type !== 'PEMINJAMAN_ASET') throw new InvalidArgumentException('Hanya request Peminjaman Aset yang bisa memproses peminjaman.');
        if (! in_array($r->status, AccessoryHandoverService::REQUEST_STATUSES, true)) throw new InvalidArgumentException('Request harus berstatus Approved atau In Progress sebelum aset dipinjamkan.');
        if ($r->returned_at) throw new InvalidArgumentException('Request ini sudah ditandai kembali.');
        $emp = $r->employee_id ? Employee::find($r->employee_id) : null;
        if (! $emp) throw new InvalidArgumentException('Pilih dan simpan karyawan peminjam terlebih dahulu.');
        $todo = $r->links()->where('kind', 'asset')->whereNull('processed_at')->get();
        if ($todo->isEmpty()) throw new InvalidArgumentException('Belum ada aset yang ditautkan (atau semuanya sudah dipinjamkan).');

        $n = 0;
        foreach ($todo as $link) {
            $asset = Asset::findOrFail($link->item_id);
            $a = $this->assets->checkout($asset, $emp, ['borrowed' => true, 'notes' => 'Peminjaman ' . ($r->ticket_number ?? '#' . $r->id) . ' — ' . $r->title]);
            $link->update(['assignment_id' => $a->id, 'processed_at' => now()]);
            $n++;
        }
        return $n;
    }

    /** Aset yang dipinjamkan lewat request ini dikembalikan ke gudang IT (status Tersedia). Aset yang sudah berpindah tangan dilewati. @return list<string> peringatan */
    public function release(ITRequest $r): array
    {
        $r = $r->fresh();
        $warn = [];
        foreach ($r->links()->where('kind', 'asset')->whereNotNull('processed_at')->get() as $link) {
            $asset = Asset::find($link->item_id);
            if (! $asset || $asset->status !== 'borrowed' || (int) $asset->employee_id !== (int) $r->employee_id) {
                $warn[] = $link->label() . ' tidak lagi berstatus Dipinjam oleh peminjam ini; statusnya tidak diubah.';
                continue;
            }
            $this->assets->checkin($asset, ['notes' => 'Pengembalian peminjaman ' . ($r->ticket_number ?? '#' . $r->id)]);
        }
        return $warn;
    }
}
