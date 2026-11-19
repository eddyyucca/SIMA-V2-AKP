<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetAssignment extends Model
{
    protected $guarded = [];
    protected $casts = ['assigned_at' => 'datetime', 'equipment_updated_at' => 'datetime'];

    public const TYPES = ['checkout' => 'Serah Terima', 'checkin' => 'Pengembalian', 'transfer' => 'Mutasi'];

    /** Nomor BAST aset: IT/BAST/2026/11/0053 (urut per bulan). Format lama (tanpa "IT/") dikonversi oleh perintah bast:renumber-assets. */
    public const NUMBER_PREFIX = 'IT/BAST/';
    public const LEGACY_NUMBER_PREFIX = 'BAST/';

    /** Waktu acuan kelengkapan pada dokumen BAST ini: terakhir diperbarui, atau tanggal BAST. */
    public function equipmentAsOf(): \Illuminate\Support\Carbon
    {
        return $this->equipment_updated_at ?? $this->assigned_at;
    }

    public function asset() { return $this->belongsTo(Asset::class)->withTrashed(); }
    public function from() { return $this->belongsTo(Employee::class, 'from_employee_id'); }
    public function to() { return $this->belongsTo(Employee::class, 'to_employee_id'); }
    public function location() { return $this->belongsTo(Location::class); }
    public function bastFiles() { return $this->hasMany(AssetBastFile::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
