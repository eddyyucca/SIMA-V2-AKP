<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Consumable extends Model
{
    use SoftDeletes, Audited, \App\Models\Concerns\HasPurchases;
    protected $guarded = [];

    protected static function booted(): void
    {
        static::created(function ($m) {
            if ((float) $m->qty > 0) {
                app(\App\Services\StockService::class)->recordInitialStock($m, (float) $m->qty);
            }
        });
    }

    public const DEFAULT_UNITS = ['pcs', 'box', 'rim', 'meter', 'roll'];

    /** Satuan yang tersedia: bawaan + yang ditambah dari form + yang sudah dipakai barang. @return array<string,string> */
    public static function unitOptions(): array
    {
        $extra = json_decode((string) Setting::get('consumable_units'), true) ?: [];
        $used = static::withTrashed()->distinct()->pluck('unit')->all();
        $all = collect([...self::DEFAULT_UNITS, ...$extra, ...$used])->filter()->unique(fn ($u) => mb_strtolower($u))->values();
        return $all->mapWithKeys(fn ($u) => [$u => $u])->all();
    }

    /** Tambah satuan baru (tanpa duplikat, tak peka huruf besar/kecil); mengembalikan nama satuan yang dipakai. */
    public static function addUnit(string $name): string
    {
        $name = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        foreach (static::unitOptions() as $u) if (mb_strtolower($u) === $name) return $u;
        $extra = json_decode((string) Setting::get('consumable_units'), true) ?: [];
        $extra[] = $name;
        Setting::put('consumable_units', json_encode(array_values($extra), JSON_UNESCAPED_UNICODE));
        return $name;
    }

    /** Awalan kode (menu Pengaturan; bawaan CSM). */
    public static function codePrefix(): string
    {
        return \App\Support\CodePrefix::get('consumable');
    }

    public function getCodeAttribute(): string
    {
        return static::codePrefix() . '-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function category() { return $this->belongsTo(Category::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function vendor() { return $this->belongsTo(Vendor::class); }
    public function movements() { return $this->morphMany(StockMovement::class, 'stockable'); }

    public function isLow(): bool { return (float) $this->qty <= (float) $this->min_qty; }
}
