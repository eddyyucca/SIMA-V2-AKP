<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu baris barang pada BAST: accessories, consumable, atau komponen. */
class AccessoryHandoverItem extends Model
{
    protected $guarded = [];

    public const KINDS = ['accessory' => Accessory::class, 'consumable' => Consumable::class, 'component' => Component::class];
    public const KIND_LABELS = ['accessory' => 'Accessories', 'consumable' => 'Consumable', 'component' => 'Komponen'];

    /** Jumlah: bilangan bulat bila bulat (accessories / komponen), desimal bila consumable berdesimal. */
    public function getQtyAttribute($value)
    {
        if ($value === null) return null;
        return fmod((float) $value, 1.0) == 0.0 ? (int) $value : (float) $value;
    }

    public function handover() { return $this->belongsTo(AccessoryHandover::class, 'accessory_handover_id'); }
    public function accessory() { return $this->belongsTo(Accessory::class)->withTrashed(); }
    public function stockable() { return $this->morphTo()->withTrashed(); }
    public function movement() { return $this->belongsTo(StockMovement::class, 'stock_movement_id'); }

    /** accessory | consumable | component */
    public function kind(): string
    {
        return array_search($this->stockable_type, self::KINDS, true) ?: 'accessory';
    }

    public function kindLabel(): string { return self::KIND_LABELS[$this->kind()]; }

    /** Barangnya (dari relasi baru; baris lama jatuh ke accessory). */
    public function item(): ?Model
    {
        return $this->stockable ?? $this->accessory;
    }
}
