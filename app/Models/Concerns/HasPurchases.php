<?php

namespace App\Models\Concerns;

use App\Models\Component;
use App\Models\StockReceipt;

/** Riwayat pembelian per barang stok (Accessory, Consumable, Component). */
trait HasPurchases
{
    public function receipts() { return $this->morphMany(StockReceipt::class, 'stockable'); }

    /** Kolom harga pada tabel barang: components.price, selain itu unit_price. */
    public function priceColumn(): string { return $this instanceof Component ? 'price' : 'unit_price'; }

    public function latestReceipt(): ?StockReceipt
    {
        return $this->receipts()->orderByDesc('received_at')->orderByDesc('id')->first();
    }

    /** Harga rata-rata tertimbang dari semua pembelian; jika belum ada pembelian, pakai harga pada barang. */
    public function getAveragePriceAttribute(): float
    {
        $r = $this->receipts()->selectRaw('SUM(qty) as q, SUM(qty * unit_price) as v')->first();
        return $r && (float) $r->q > 0 ? round((float) $r->v / (float) $r->q, 2) : (float) $this->{$this->priceColumn()};
    }

    /** Nilai stok = stok total × harga rata-rata. */
    public function getStockValueAttribute(): float
    {
        $qty = $this instanceof \App\Models\Consumable ? (float) $this->qty : (float) $this->qty_total;
        return round($qty * $this->average_price, 2);
    }

    /** Samakan harga terakhir & vendor terakhir pada barang dengan pembelian terbaru. */
    public function refreshPurchaseSummary(): void
    {
        $last = $this->latestReceipt();
        if (! $last) return;
        $this->forceFill([$this->priceColumn() => $last->unit_price, 'vendor_id' => $last->vendor_id ?? $this->vendor_id])->saveQuietly();
    }
}
