<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** Satu kali pembelian/penerimaan stok (batch): vendor, harga satuan, tanggal, nomor PO. */
class StockReceipt extends Model
{
    use Audited;

    protected $guarded = [];
    protected $casts = ['received_at' => 'date', 'qty' => 'decimal:2', 'unit_price' => 'decimal:2'];

    protected static function booted(): void
    {
        // harga terakhir & vendor terakhir pada barang selalu mengikuti pembelian terbaru
        static::saved(fn (StockReceipt $r) => $r->stockable?->refreshPurchaseSummary());
    }

    public function stockable() { return $this->morphTo()->withTrashed(); }
    public function vendor() { return $this->belongsTo(Vendor::class)->withTrashed(); }
    public function movement() { return $this->belongsTo(StockMovement::class, 'stock_movement_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function getTotalAttribute(): float { return round((float) $this->qty * (float) $this->unit_price, 2); }
}
