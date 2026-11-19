<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Component extends Model
{
    use SoftDeletes, Audited, \App\Models\Concerns\HasPurchases, \App\Models\Concerns\HasUniqueSerial;
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function ($m) { $m->qty_available = $m->qty_total; });
        static::created(function ($m) {
            if ((float) $m->qty_total > 0) {
                app(\App\Services\StockService::class)->recordInitialStock($m, (float) $m->qty_total);
            }
        });
    }
    protected $casts = ['warranty_until' => 'date'];

    /** Kode untuk label & scan, mis. CMP-0007. */
    /** Awalan kode (menu Pengaturan; bawaan CMP). */
    public static function codePrefix(): string
    {
        return \App\Support\CodePrefix::get('component');
    }

    public function getCodeAttribute(): string
    {
        return static::codePrefix() . '-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function category() { return $this->belongsTo(Category::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function vendor() { return $this->belongsTo(Vendor::class); }
    public function movements() { return $this->morphMany(StockMovement::class, 'stockable'); }

    public function isLow(): bool { return $this->qty_available <= $this->min_qty; }
}
