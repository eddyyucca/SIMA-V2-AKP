<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Dokumen BAST serah terima / pengembalian accessories antara IT dan karyawan. */
class AccessoryHandover extends Model
{
    protected $guarded = [];
    protected $casts = ['handed_at' => 'datetime', 'meta' => 'array'];

    public const TYPES = ['checkout' => 'Serah Terima', 'return' => 'Pengembalian', 'install' => 'Pemasangan Komponen'];
    /** Jenis yang boleh dipilih di form BAST manual (pemasangan komponen dibuat dari aksi Pasang ke Aset). */
    public const FORM_TYPES = ['checkout' => 'Serah Terima', 'return' => 'Pengembalian'];
    public const INSTALL_REASONS = ['Upgrade' => 'Upgrade', 'Perbaikan' => 'Perbaikan', 'Penambahan' => 'Penambahan'];
    /** Judul dokumen per jenis barang (satu jenis saja) dan untuk campuran. Kunci: jenis atau 'mixed'. */
    public const TITLES = [
        'checkout' => [
            'consumable' => 'BUKTI PENGELUARAN BARANG CONSUMABLE', 'accessory' => 'BUKTI SERAH TERIMA ACCESSORIES',
            'component' => 'BUKTI SERAH TERIMA KOMPONEN', 'mixed' => 'BUKTI SERAH TERIMA BARANG PENDUKUNG',
        ],
        'return' => ['accessory' => 'BUKTI PENGEMBALIAN ACCESSORIES', 'mixed' => 'BUKTI PENGEMBALIAN BARANG PENDUKUNG'],
        'install' => ['component' => 'BUKTI PEMASANGAN KOMPONEN ASET', 'mixed' => 'BUKTI PEMASANGAN KOMPONEN ASET'],
    ];

    /** Kode jenis dokumen pada nomor: 010/IT/BPB/AKP/XI/2026. */
    public const CODES = [
        'checkout' => ['consumable' => 'BPB', 'accessory' => 'BSA', 'component' => 'BSK', 'mixed' => 'BSP'],
        'return' => ['accessory' => 'BPA', 'mixed' => 'BPA'],
        'install' => ['component' => 'BPK', 'mixed' => 'BPK'],
    ];

    /** Satu jenis barang saja -> jenis itu; beberapa jenis -> 'mixed'. */
    public static function kindOf(iterable $kinds): string
    {
        $u = collect($kinds)->unique()->values();
        return $u->count() === 1 ? $u->first() : 'mixed';
    }

    public function kind(): string
    {
        return static::kindOf($this->items->map->kind());
    }

    public function title(): string
    {
        return self::TITLES[$this->type][$this->kind()] ?? self::TITLES[$this->type]['mixed'];
    }

    /** Tujuan stok keluar. Karyawan = pemegang barang (bisa dikembalikan); lainnya hanya tercatat keluar. */
    public const TARGETS = ['employee' => 'Karyawan', 'department' => 'Department', 'location' => 'Lokasi', 'asset' => 'Perangkat'];

    public function department() { return $this->belongsTo(Department::class)->withTrashed(); }
    public function location() { return $this->belongsTo(Location::class)->withTrashed(); }
    public function asset() { return $this->belongsTo(Asset::class)->withTrashed(); }
    public function pic() { return $this->belongsTo(Employee::class, 'pic_id')->withTrashed(); }

    /** Nama penerima untuk daftar: "Budi", "Department: IT", "Lokasi: Gudang", "Perangkat: AKP-IT-NB-0001". */
    public function recipientLabel(): string
    {
        return match ($this->target_type) {
            'department' => 'Department: ' . ($this->department?->name ?? '-'),
            'location' => 'Lokasi: ' . ($this->location?->name ?? '-'),
            'asset' => 'Perangkat: ' . ($this->asset?->asset_tag ?? '-'),
            default => $this->employee?->name ?? '-',
        };
    }

    public function employee() { return $this->belongsTo(Employee::class)->withTrashed(); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function request() { return $this->belongsTo(ITRequest::class, 'request_id'); }
    public function items() { return $this->hasMany(AccessoryHandoverItem::class); }
    public function files() { return $this->hasMany(AccessoryHandoverFile::class)->latest('id'); }

    /** Ringkas isi: "Mouse Wireless × 1, Keyboard × 2". */
    public function itemsSummary(): string
    {
        return $this->items->map(fn ($i) => ($i->action ? ($i->action === 'remove' ? 'Lepas ' : 'Pasang ') : '') . ($i->item()?->name ?? '-') . ' × ' . $i->qty)->implode(', ');
    }

    public function downloadName(): string
    {
        return str_replace('/', '-', $this->bast_number) . '.pdf';
    }
}
