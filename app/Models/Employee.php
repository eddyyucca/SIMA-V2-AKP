<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes, Audited;
    protected $guarded = [];
    protected $casts = ['hris_missing_at' => 'datetime', 'hris_synced_at' => 'datetime'];

    protected static function booted(): void
    {
        // karyawan resign -> akun loginnya otomatis dinonaktifkan (tidak dihapus, supaya riwayat tetap utuh)
        static::saved(function (Employee $e) {
            if ($e->status === 'resigned' && $e->user_id) {
                User::whereKey($e->user_id)->where('is_active', true)->update(['is_active' => false]);
            }
        });
    }

    public function department() { return $this->belongsTo(Department::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function assets() { return $this->hasMany(Asset::class); }
    public function licenseSeats() { return $this->hasMany(LicenseSeat::class)->whereNull('revoked_at'); }

    /** Riwayat BAST barang pendukung (accessories, consumable, komponen) yang pernah diserahkan / dikembalikan, terbaru dulu. */
    public function accessoryHandovers() { return $this->hasMany(AccessoryHandover::class)->orderByDesc('handed_at')->orderByDesc('id'); }

    public function isActive(): bool { return $this->status === 'active'; }

    /** Accessories yang sedang dipegang (out - return). */
    public function heldAccessories()
    {
        return StockMovement::query()
            ->where('stockable_type', Accessory::class)
            ->where('employee_id', $this->id)
            ->whereIn('type', ['out', 'return'])
            ->get()
            ->groupBy('stockable_id')
            ->map(function ($rows, $id) {
                $qty = $rows->sum(fn ($r) => $r->type === 'out' ? $r->qty : -$r->qty);
                return ['accessory' => Accessory::withTrashed()->find($id), 'qty' => (int) $qty];
            })
            ->filter(fn ($r) => $r['qty'] > 0)
            ->values();
    }

    public function holdsItems(): bool
    {
        return $this->assets()->exists() || $this->heldAccessories()->isNotEmpty() || $this->licenseSeats()->exists();
    }
}
