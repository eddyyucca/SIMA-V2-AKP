<?php

namespace App\Services;

use App\Models\Accessory;
use App\Models\AccessoryHandover;
use App\Models\AccessoryHandoverFile;
use App\Models\AccessoryHandoverItem;
use App\Models\Component;
use App\Models\Asset;
use App\Models\Consumable;
use App\Models\Department;
use App\Models\Location;
use App\Models\Employee;
use App\Models\ITRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Serah terima barang pendukung (accessories, consumable, komponen) antara IT dan karyawan dengan dokumen BAST: stok berubah lewat StockService (satu transaksi dengan
 * pembuatan BAST, jadi kalau salah satu gagal tidak ada yang tersimpan), nomor BAST berurutan per bulan, bisa terkait request ITR
 * dari System Requisition, dan scan BAST bertanda tangan diarsipkan.
 */
class AccessoryHandoverService
{
    /** Status request ITR yang boleh dikaitkan dengan serah terima (sudah disetujui, belum final). */
    public const REQUEST_STATUSES = ['Approved', 'In Progress'];

    public function __construct(private StockService $stock) {}

    /**
     * Stok keluar dengan dokumen BAST. $lines = [['item' => 'accessory:1', 'qty' => 2], ...] (barang yang sama digabung).
     * Tujuan ($target): ['type' => 'employee'] (bawaan; $employee = penerima), atau 'department' / 'location' / 'asset' dengan
     * 'department_id' / 'location_id' / 'asset_id'; pada tujuan non-karyawan $employee opsional sebagai PIC penerima.
     * $target['from_plan'] = true (hanya dari ArrivalPlanService): stok yang dicadangkan untuk rencana itu boleh dipakai.
     * 'asset' hanya untuk accessories dan consumable (kelengkapan perangkat; komponen dipasang lewat aksi Pasang ke Aset). Karyawan yang menerima tercatat sebagai pemegang (bisa dikembalikan), tujuan lain hanya tercatat keluar.
     */
    public function checkout(?Employee $employee, array $lines, ?int $requestId = null, ?string $notes = null, array $target = []): AccessoryHandover
    {
        $type = $target['type'] ?? 'employee';
        if (! isset(AccessoryHandover::TARGETS[$type])) throw new InvalidArgumentException('Tujuan stok keluar tidak dikenal.');
        if ($employee && ! $employee->isActive()) {
            throw new InvalidArgumentException($type === 'employee' ? 'Karyawan berstatus resigned tidak bisa menerima barang baru.' : 'PIC penerima berstatus resigned.');
        }
        $lines = $this->normalize($lines);
        $dest = ['target_type' => $type, 'employee_id' => null, 'pic_id' => null, 'department_id' => null, 'location_id' => null, 'asset_id' => null];
        switch ($type) {
            case 'employee':
                if (! $employee) throw new InvalidArgumentException('Pilih karyawan penerima.');
                $dest['employee_id'] = $employee->id;
                break;
            case 'department':
                if (empty($target['department_id']) || ! Department::whereKey($target['department_id'])->exists()) throw new InvalidArgumentException('Pilih department tujuan.');
                $dest['department_id'] = (int) $target['department_id']; $dest['pic_id'] = $employee?->id;
                break;
            case 'location':
                if (empty($target['location_id']) || ! Location::whereKey($target['location_id'])->exists()) throw new InvalidArgumentException('Pilih lokasi tujuan.');
                $dest['location_id'] = (int) $target['location_id']; $dest['pic_id'] = $employee?->id;
                break;
            case 'asset':
                if (collect($lines)->contains(fn ($l) => $l['kind'] === 'component')) throw new InvalidArgumentException('Tujuan perangkat hanya untuk accessories dan consumable. Komponen dipasang lewat aksi Pasang ke Aset.');
                if (empty($target['asset_id']) || ! Asset::whereKey($target['asset_id'])->exists()) throw new InvalidArgumentException('Pilih perangkat tujuan.');
                $dest['asset_id'] = (int) $target['asset_id']; $dest['pic_id'] = $employee?->id;
                break;
        }
        $request = $requestId ? $this->linkableRequest($requestId, $type === 'employee' ? $employee : null) : null;
        return $this->create('checkout', $employee, $lines, $request, $notes, $dest, ! empty($target['from_plan']));
    }

    /**
     * Accessories yang sedang berada di sebuah tujuan non-karyawan (department / location / asset), dihitung dari stok keluar dikurangi pengembalian.
     * Bentuk sama dengan Employee::heldAccessories(): [['accessory' => Accessory, 'qty' => int]].
     */
    public function heldAt(string $targetType, int $targetId)
    {
        $col = ['department' => 'department_id', 'location' => 'location_id', 'asset' => 'asset_id'][$targetType] ?? null;
        if (! $col) return collect();
        return \App\Models\StockMovement::query()
            ->where('stockable_type', Accessory::class)->where($col, $targetId)->whereIn('type', ['out', 'return'])->get()
            ->groupBy('stockable_id')
            ->map(fn ($rows, $id) => ['accessory' => Accessory::withTrashed()->find($id), 'qty' => (int) $rows->sum(fn ($r) => $r->type === 'out' ? $r->qty : -$r->qty)])
            ->filter(fn ($r) => $r['qty'] > 0)->values();
    }

    /**
     * Pengembalian ke IT. Bawaan dari karyawan; $target ['type' => 'department'|'location'|'asset', '<..>_id' => ..] untuk barang yang
     * sebelumnya keluar ke tujuan itu ($employee opsional sebagai PIC yang menyerahkan). $lines = [['item' => 'accessory:1', 'qty', 'condition']];
     * tidak boleh melebihi yang masih berada di tujuan itu.
     */
    public function receiveBack(?Employee $employee, array $lines, ?string $notes = null, array $target = []): AccessoryHandover
    {
        $type = $target['type'] ?? 'employee';
        if (! isset(AccessoryHandover::TARGETS[$type])) throw new InvalidArgumentException('Tujuan pengembalian tidak dikenal.');
        $lines = $this->normalize($lines);
        foreach ($lines as $l) {
            if ($l['kind'] !== 'accessory') {
                throw new InvalidArgumentException('Pengembalian hanya untuk accessories. ' . AccessoryHandoverItem::KIND_LABELS[$l['kind']] . ' dicatat sebagai barang keluar dan tidak dikembalikan lewat BAST.');
            }
        }
        if ($type === 'employee') {
            if (! $employee) throw new InvalidArgumentException('Pilih karyawan yang mengembalikan.');
            $held = collect($employee->heldAccessories())->mapWithKeys(fn ($h) => [$h['accessory']?->id => $h['qty']]);
            $who = $employee->name;
            $dest = ['target_type' => 'employee', 'employee_id' => $employee->id];
        } else {
            if ($employee && ! $employee->isActive()) throw new InvalidArgumentException('PIC berstatus resigned.');
            $col = ['department' => 'department_id', 'location' => 'location_id', 'asset' => 'asset_id'][$type];
            $model = ['department' => Department::class, 'location' => Location::class, 'asset' => Asset::class][$type];
            $id = (int) ($target[$col] ?? 0);
            if (! $id || ! $model::whereKey($id)->exists()) throw new InvalidArgumentException('Pilih ' . strtolower(AccessoryHandover::TARGETS[$type]) . ' asal barang.');
            $held = $this->heldAt($type, $id)->mapWithKeys(fn ($h) => [$h['accessory']?->id => $h['qty']]);
            $who = AccessoryHandover::TARGETS[$type] . ' ini';
            $dest = ['target_type' => $type, $col => $id, 'pic_id' => $employee?->id];
        }
        foreach ($lines as $l) {
            $has = (int) ($held[$l['id']] ?? 0);
            if ($l['qty'] > $has) {
                $name = Accessory::withTrashed()->find($l['id'])?->name ?? 'barang';
                throw new InvalidArgumentException("{$who} hanya memegang {$has} unit {$name}.");
            }
        }
        return $this->create('return', $employee, $lines, null, $notes, $dest);
    }

    /**
     * Pasang / lepas komponen pada aset (upgrade, perbaikan) dengan dokumen BAST pemasangan.
     * $install = [['component_id' => 1, 'qty' => 1]]; $remove = [['component_id' => 2, 'qty' => 1, 'condition' => 'good'|'broken', 'note' => '...']].
     * Aset boleh belum punya pemegang (stok gudang IT); pemegang saat itu dicatat di dokumen. Komponen lama yang rusak tidak kembali ke stok
     * (dihapus dari stok); yang baik kembali tersedia. $spec = ['key' => 'ram', 'value' => '16 GB DDR4'] memperbarui spesifikasi aset
     * (sebelum -> sesudah tercatat). Semua dalam satu transaksi: gagal di tengah = tidak ada yang berubah.
     */
    public function installComponents(Asset $asset, array $install, array $remove = [], string $reason = 'Upgrade', ?string $notes = null, ?int $requestId = null, ?int $picId = null, array $spec = []): AccessoryHandover
    {
        if (in_array($asset->status, [...Asset::INACTIVE, 'lost'], true)) throw new InvalidArgumentException('Aset berstatus ' . (Asset::STATUSES[$asset->status] ?? $asset->status) . ' tidak bisa dimodifikasi.');
        if (! isset(AccessoryHandover::INSTALL_REASONS[$reason])) throw new InvalidArgumentException('Alasan harus Upgrade, Perbaikan, atau Penambahan.');
        $pic = $picId ? Employee::find($picId) : null;
        if ($picId && (! $pic || ! $pic->isActive())) throw new InvalidArgumentException('Peminta harus karyawan aktif.');
        $request = $requestId ? $this->linkableRequest($requestId, null) : null;

        $merge = function (array $lines, bool $removal): array {
            $out = [];
            foreach ($lines as $l) {
                $id = (int) ($l['component_id'] ?? 0);
                if (! $id) continue;
                $qty = $l['qty'] ?? 0;
                if (! is_numeric($qty) || (float) $qty < 1 || (float) $qty != (int) $qty) throw new InvalidArgumentException('Jumlah komponen harus bilangan bulat minimal 1.');
                if (! Component::whereKey($id)->exists()) throw new InvalidArgumentException('Komponen yang dipilih tidak ditemukan.');
                $cond = $removal ? (($l['condition'] ?? 'good') === 'broken' ? 'broken' : 'good') : null;
                $k = $id . ':' . $cond;
                $out[$k] = ['id' => $id, 'qty' => ($out[$k]['qty'] ?? 0) + (int) $qty, 'condition' => $cond, 'note' => $l['note'] ?? ($out[$k]['note'] ?? null)];
            }
            return array_values($out);
        };
        $install = $merge($install, false);
        $remove = $merge($remove, true);
        if (! $install && ! $remove) throw new InvalidArgumentException('Pilih komponen yang dipasang atau dilepas.');

        $doc = DB::transaction(function () use ($asset, $install, $remove, $reason, $notes, $request, $pic, $spec) {
            $a = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            $installed = $a->installedComponents();
            foreach ($remove as $r) {
                $has = (int) ($installed->firstWhere(fn ($x) => $x['component']?->id === $r['id'])['qty'] ?? 0);
                if ($r['qty'] > $has) {
                    $name = Component::withTrashed()->find($r['id'])?->name ?? 'komponen';
                    throw new InvalidArgumentException("{$name} hanya terpasang {$has} unit pada aset ini.");
                }
            }

            // perubahan spesifikasi terstruktur (sebelum -> sesudah)
            $changes = [];
            $key = (string) ($spec['key'] ?? ''); $val = trim((string) ($spec['value'] ?? ''));
            if ($key !== '' && $val !== '') {
                $fields = Asset::specFields();
                if (! isset($fields[$key])) throw new InvalidArgumentException('Kolom spesifikasi tidak dikenal.');
                $before = $a->spec_data[$key] ?? null;
                $data = $a->spec_data ?? [];
                $data[$key] = mb_substr($val, 0, 255);
                $a->spec_data = $data;
                if (($before ?? '') !== $data[$key]) $changes[] = ['key' => $key, 'label' => $fields[$key][0], 'from' => $before, 'to' => $data[$key]];
            }

            $h = AccessoryHandover::create([
                'bast_number' => $this->nextNumber('install', 'component'), 'type' => 'install', 'target_type' => 'asset', 'asset_id' => $a->id,
                'employee_id' => $a->employee_id,                    // pemegang aset saat itu (kosong = stok gudang IT)
                'pic_id' => $pic?->id, 'request_id' => $request?->id, 'reason' => $reason, 'handed_at' => now(),
                'notes' => filled($notes) ? mb_substr(trim($notes), 0, 1000) : null, 'created_by' => auth()->id(),
                'meta' => $changes ? ['spec_changes' => $changes] : null,
            ]);

            $parts = [];
            foreach ($remove as $r) {
                $c = Component::findOrFail($r['id']);
                $m = $this->stock->move($c, 'uninstall', $r['qty'], array_filter(['asset_id' => $a->id, 'reason' => $reason . ' — ' . $h->bast_number, 'condition' => $r['condition'], 'notes' => $r['note']], fn ($v) => $v !== null));
                if ($r['condition'] === 'broken') {
                    // rusak: tidak boleh kembali ke stok tersedia -> dihapus dari stok (total & tersedia berkurang)
                    $this->stock->move($c, 'adjust', -$r['qty'], ['asset_id' => $a->id, 'reason' => 'Komponen lama rusak dilepas — ' . $h->bast_number, 'notes' => $r['note']]);
                }
                $h->items()->create(['stockable_type' => Component::class, 'stockable_id' => $c->id, 'qty' => $r['qty'], 'action' => 'remove', 'condition' => $r['condition'] === 'broken' ? 'major_damage' : 'good', 'stock_movement_id' => $m->id]);
                $parts[] = "lepas {$c->name} x{$r['qty']}" . ($r['condition'] === 'broken' ? ' (rusak)' : '');
            }
            foreach ($install as $i) {
                $c = Component::findOrFail($i['id']);
                $m = $this->stock->move($c, 'install', $i['qty'], ['asset_id' => $a->id, 'reason' => $reason . ' — ' . $h->bast_number]);
                $h->items()->create(['stockable_type' => Component::class, 'stockable_id' => $c->id, 'qty' => $i['qty'], 'action' => 'install', 'stock_movement_id' => $m->id]);
                array_unshift($parts, "pasang {$c->name} x{$i['qty']}");
            }
            foreach ($changes as $c) $parts[] = "spesifikasi {$c['label']}: " . ($c['from'] ?: '-') . ' → ' . $c['to'];
            $a->specs = trim(($a->specs ? $a->specs . "\n" : '') . '[' . now()->format('d/m/Y') . "] {$reason}: " . implode(', ', $parts) . " ({$h->bast_number})");
            $a->save();

            return $h->load(['items.stockable', 'employee', 'asset', 'pic', 'request']);
        });

        // kelengkapan pada BAST pemegang ikut diperbarui (di luar transaksi: gagal membuat PDF tidak membatalkan pemasangan)
        try {
            app(AssetService::class)->refreshEquipment($asset);
        } catch (\Throwable $e) {
            report($e);
        }
        return $doc;
    }

    /**
     * Gabungkan barang yang sama, pastikan jumlah bulat >= 1 dan barangnya ada.
     * Baris boleh berbentuk ['item' => 'accessory:5' | 'consumable:2' | 'component:3'] atau (lama) ['accessory_id' => 5].
     */
    private function normalize(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (! empty($l['item']) && str_contains((string) $l['item'], ':')) {
                [$kind, $id] = explode(':', (string) $l['item'], 2);
                $id = (int) $id;
            } else {
                $kind = 'accessory'; $id = (int) ($l['accessory_id'] ?? 0);
            }
            if (! $id) continue;                                         // baris kosong dilewati
            $class = AccessoryHandoverItem::KINDS[$kind] ?? null;
            if (! $class) throw new InvalidArgumentException('Jenis barang tidak dikenal.');
            $qty = $l['qty'] ?? 0;
            if (! is_numeric($qty)) throw new InvalidArgumentException('Jumlah tiap barang minimal 1.');
            if ($kind === 'consumable') {
                // consumable boleh desimal (mis. 1,5 meter), maksimal 2 angka di belakang koma
                $qty = round((float) $qty, 2);
                if ($qty < 0.01) throw new InvalidArgumentException('Jumlah tiap barang minimal 0,01.');
            } else {
                if ((float) $qty < 1) throw new InvalidArgumentException('Jumlah tiap barang minimal 1.');
                if ((float) $qty != (int) $qty) throw new InvalidArgumentException('Jumlah harus bilangan bulat.');
                $qty = (int) $qty;
            }
            if (! $class::whereKey($id)->exists()) {
                throw new InvalidArgumentException(['accessory' => 'Accessories', 'consumable' => 'Consumable', 'component' => 'Komponen'][$kind] . ' yang dipilih tidak ditemukan.');
            }
            $k = "$kind:$id";
            $out[$k] = ['kind' => $kind, 'id' => $id, 'accessory_id' => $kind === 'accessory' ? $id : null, 'qty' => ($out[$k]['qty'] ?? 0) + $qty,
                'condition' => $l['condition'] ?? ($out[$k]['condition'] ?? null)];
        }
        if (! $out) throw new InvalidArgumentException('Pilih minimal satu barang.');
        return array_values($out);
    }

    private function linkableRequest(int $requestId, ?Employee $employee): ITRequest
    {
        $req = ITRequest::find($requestId);
        if (! $req) throw new InvalidArgumentException('Request tidak ditemukan.');
        if ($req->type !== 'ITR') throw new InvalidArgumentException('Serah terima accessories hanya bisa dikaitkan dengan request ITR.');
        if (! in_array($req->status, self::REQUEST_STATUSES, true)) {
            throw new InvalidArgumentException("Request {$req->ticket_number} berstatus {$req->status}; hanya yang sudah disetujui dan belum Completed yang bisa dikaitkan.");
        }
        $owner = $req->user_id ? Employee::where('user_id', $req->user_id)->value('id') : null;
        if ($employee && $owner && (int) $owner !== (int) $employee->id) {
            throw new InvalidArgumentException('Penerima harus sama dengan karyawan yang mengajukan request ini.');
        }
        return $req;
    }

    private function create(string $type, ?Employee $employee, array $lines, ?ITRequest $request, ?string $notes, array $dest, bool $fromPlan = false): AccessoryHandover
    {
        $dest += ['target_type' => 'employee', 'employee_id' => null, 'pic_id' => null, 'department_id' => null, 'location_id' => null, 'asset_id' => null];
        return DB::transaction(function () use ($type, $employee, $lines, $request, $notes, $dest, $fromPlan) {
            $number = $this->nextNumber($type, AccessoryHandover::kindOf(array_column($lines, 'kind')));
            $h = AccessoryHandover::create([
                'bast_number' => $number, 'type' => $type, 'request_id' => $request?->id, ...$dest,
                'handed_at' => now(), 'notes' => filled($notes) ? mb_substr(trim($notes), 0, 1000) : null, 'created_by' => auth()->id(),
            ]);
            foreach ($lines as $l) {
                $class = AccessoryHandoverItem::KINDS[$l['kind']];
                $item = $class::findOrFail($l['id']);
                $movement = $this->stock->move($item, $type === 'checkout' ? 'out' : 'return', $l['qty'], array_filter([
                    'employee_id' => $dest['employee_id'], 'department_id' => $dest['department_id'], 'location_id' => $dest['location_id'], 'asset_id' => $dest['asset_id'],
                    'reason' => ($type === 'checkout' ? 'Serah terima ' : 'Pengembalian ') . $number,
                    'condition' => $l['condition'] ?? null,
                    'notes' => $request ? 'Request ' . $request->ticket_number : null,
                    'from_plan' => $fromPlan ?: null,                  // barang yang dicadangkan untuk rencana kedatangan ini boleh keluar
                ], fn ($v) => $v !== null));
                $h->items()->create(['stockable_type' => $class, 'stockable_id' => $item->id, 'accessory_id' => $l['accessory_id'], 'qty' => $l['qty'], 'condition' => $type === 'return' ? ($l['condition'] ?: 'good') : null, 'stock_movement_id' => $movement->id]);
            }
            if ($request && $type === 'checkout') app(RequestLifecycle::class)->handedOver($request, $h->load('items'));   // status request ITERA ikut maju + pemohon dikabari
            return $h->load(['items.stockable', 'employee', 'request', 'department', 'location', 'asset', 'pic']);
        });
    }

    /**
     * Nomor dokumen 010/IT/BPB/AKP/XI/2026: urut/IT/KODE-JENIS/AKP/bulan-romawi/tahun. Urutan per kode per tahun
     * (dikunci agar tidak ganda). Dokumen lama (BAST-ACC/2026/11/0001) tidak diubah.
     */
    public function nextNumber(string $type, string $kind, ?\Illuminate\Support\Carbon $at = null): string
    {
        $at ??= now();
        $code = AccessoryHandover::CODES[$type][$kind] ?? AccessoryHandover::CODES[$type]['mixed'];
        $year = $at->format('Y');
        $suffix = "/IT/{$code}/AKP/";
        $last = AccessoryHandover::where(fn ($q) => $q->where('bast_number', 'like', '%' . $suffix . '%/' . $year)->orWhere('bast_number', 'like', "%/IT/{$code}/HO/AKP/%/" . $year))->lockForUpdate()   // format lama (dengan HO) tetap dihitung
            ->pluck('bast_number')->map(fn ($n) => (int) explode('/', $n)[0])->max();
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][(int) $at->format('n') - 1];
        return str_pad((string) (($last ?? 0) + 1), 3, '0', STR_PAD_LEFT) . $suffix . $roman . '/' . $year;
    }

    /** Arsipkan scan BAST bertanda tangan (PDF / JPG / PNG). Setiap unggahan disimpan permanen; tidak ada yang ditimpa. */
    public function attachSigned(AccessoryHandover $h, string $path, ?string $originalName = null, ?string $note = null): AccessoryHandoverFile
    {
        $disk = Storage::disk('local');
        if (! str_starts_with($path, 'accessory-bast-signed/') || str_contains($path, '..') || ! $disk->exists($path)) {
            throw new InvalidArgumentException('File tidak ditemukan, unggah ulang.');
        }
        $head = (string) substr((string) $disk->get($path), 0, 8);
        $valid = str_starts_with($head, '%PDF') || str_starts_with($head, "\xFF\xD8\xFF") || str_starts_with($head, "\x89PNG");
        if (! $valid) {
            $disk->delete($path);
            throw new InvalidArgumentException('File bukan PDF / JPG / PNG yang valid.');
        }
        return $h->files()->create([
            'path' => $path, 'original_name' => filled($originalName) ? mb_substr(basename($originalName), 0, 255) : null,
            'note' => filled($note) ? mb_substr(trim($note), 0, 250) : null, 'uploaded_by' => auth()->id(),
        ]);
    }
}
