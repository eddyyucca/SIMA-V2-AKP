<?php

namespace App\Services;

use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Employee;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockService
{
    /**
     * Semua perubahan stok wajib lewat sini: tulis stock_movements + update kolom cache,
     * dalam satu DB transaction dengan lockForUpdate().
     */
    public function move(Model $item, string $type, float $qty, array $meta = []): StockMovement
    {
        if ($qty == 0) {
            throw new InvalidArgumentException('Jumlah tidak boleh nol.');
        }
        if ($type !== 'adjust' && $qty < 0) {
            throw new InvalidArgumentException('Jumlah harus lebih dari nol.');
        }
        if (! empty($meta['employee_id']) && in_array($type, ['out'], true)) {
            $emp = Employee::find($meta['employee_id']);
            if ($emp && ! $emp->isActive()) {
                throw new InvalidArgumentException('Karyawan berstatus resigned tidak bisa menerima barang baru.');
            }
        }

        $movement = DB::transaction(function () use ($item, $type, $qty, $meta) {
            $locked = $item::query()->lockForUpdate()->findOrFail($item->getKey());

            if ($locked instanceof Consumable) {
                $delta = match ($type) {
                    'in', 'return' => $qty,
                    'out' => -$qty,
                    'adjust' => $qty,
                    default => throw new InvalidArgumentException("Tipe $type tidak valid untuk consumable."),
                };
                if ((float) $locked->qty + $delta < 0) {
                    throw new InvalidArgumentException('Stok keluar melebihi stok tersedia.');
                }
                $locked->qty = (float) $locked->qty + $delta;
            } else {
                $qty = (int) $qty;
                [$dTotal, $dAvail] = match ($type) {
                    'in' => [$qty, $qty],
                    'out' => $locked instanceof Accessory ? [0, -$qty] : [-$qty, -$qty],
                    'return' => [0, $qty],
                    'install' => $locked instanceof Component ? [0, -$qty] : throw new InvalidArgumentException('Hanya komponen yang bisa dipasang.'),
                    'uninstall' => $locked instanceof Component ? [0, $qty] : throw new InvalidArgumentException('Hanya komponen yang bisa dilepas.'),
                    'adjust' => [$qty, $qty],
                };
                if ($type === 'out' && $locked instanceof Accessory && empty($meta['from_plan'])) {
                    $reserved = (int) \App\Models\ArrivalPlanItem::where('accessory_id', $locked->id)->whereIn('status', \App\Models\ArrivalPlanItem::RESERVING)->sum('qty');
                    $free = $locked->qty_available - $reserved;
                    if ($reserved > 0 && $qty > $free) {
                        throw new InvalidArgumentException("Stok yang bebas hanya {$free}; {$reserved} unit dicadangkan untuk rencana kedatangan karyawan.");
                    }
                }
                if ($locked->qty_available + $dAvail < 0) {
                    throw new InvalidArgumentException('Stok keluar melebihi stok tersedia.');
                }
                if ($locked->qty_total + $dTotal < 0 || $locked->qty_available + $dAvail > $locked->qty_total + $dTotal) {
                    throw new InvalidArgumentException('Jumlah tidak valid terhadap total stok.');
                }
                $locked->qty_total += $dTotal;
                $locked->qty_available += $dAvail;
            }
            $locked->save();

            $m = $locked->movements()->create([
                'type' => $type,
                'qty' => $qty,
                'employee_id' => $meta['employee_id'] ?? null,
                'asset_id' => $meta['asset_id'] ?? null,
                'department_id' => $meta['department_id'] ?? null,
                'location_id' => $meta['location_id'] ?? null,
                'reason' => $meta['reason'] ?? null,
                'condition' => $meta['condition'] ?? null,
                'notes' => $meta['notes'] ?? null,
                'moved_at' => $meta['moved_at'] ?? (! empty($meta['received_at']) ? \Illuminate\Support\Carbon::parse($meta['received_at'])->setTimeFrom(now()) : now()),
                'created_by' => $meta['created_by'] ?? auth()->id(),
            ]);

            if ($type === 'in') {
                // setiap stok masuk = satu pembelian (vendor, harga satuan, tanggal, no. PO); barang ikut diperbarui lewat StockReceipt
                \App\Models\StockReceipt::create([
                    'stockable_type' => $locked->getMorphClass(), 'stockable_id' => $locked->getKey(), 'stock_movement_id' => $m->id,
                    'vendor_id' => $meta['vendor_id'] ?? $locked->vendor_id,
                    'qty' => $qty, 'unit_price' => $meta['unit_price'] ?? (float) $locked->{$locked->priceColumn()},
                    'received_at' => $m->moved_at->toDateString(), 'reference_no' => $meta['reference_no'] ?? null,
                    'notes' => $meta['notes'] ?? null, 'created_by' => $m->created_by,
                ]);
            }

            $item->setRawAttributes($locked->fresh()->getAttributes(), true);

            return $m;
        });

        if (in_array($type, ['out', 'install', 'adjust'], true) && $item->isLow()) {
            app(NotificationService::class)->lowStock($item);
        }

        return $movement;
    }

    /** Stok awal saat barang dibuat: satu pergerakan "masuk" + satu pembelian (vendor & harga dari isian barang). */
    public function recordInitialStock(Model $item, float $qty): void
    {
        $m = $item->movements()->create(['type' => 'in', 'qty' => $qty, 'reason' => 'Stok awal', 'moved_at' => now(), 'created_by' => auth()->id()]);
        \App\Models\StockReceipt::create([
            'stockable_type' => $item->getMorphClass(), 'stockable_id' => $item->getKey(), 'stock_movement_id' => $m->id,
            'vendor_id' => $item->vendor_id, 'qty' => $qty, 'unit_price' => (float) $item->{$item->priceColumn()},
            'received_at' => now()->toDateString(), 'reference_no' => null, 'notes' => 'Stok awal', 'created_by' => auth()->id(),
        ]);
    }
}
