<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Component;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ComponentService
{
    public function install(Component $c, Asset $asset, int $qty, string $reason, ?Component $old = null, int $oldQty = 1, ?string $oldNote = null): void
    {
        if (in_array($asset->status, \App\Models\Asset::INACTIVE, true)) {
            throw new InvalidArgumentException('Aset disposed tidak bisa dimodifikasi.');
        }
        DB::transaction(function () use ($c, $asset, $qty, $reason, $old, $oldQty, $oldNote) {
            $stock = app(StockService::class);
            if ($old) {
                $installed = $asset->installedComponents()->firstWhere(fn ($r) => $r['component']?->id === $old->id)['qty'] ?? 0;
                if ($oldQty > $installed) {
                    throw new InvalidArgumentException("Komponen lama hanya terpasang {$installed} unit pada aset ini.");
                }
                $stock->move($old, 'uninstall', $oldQty, ['asset_id' => $asset->id, 'reason' => $reason, 'notes' => $oldNote]);
            }
            $stock->move($c, 'install', $qty, ['asset_id' => $asset->id, 'reason' => $reason]);

            $a = Asset::query()->lockForUpdate()->find($asset->id);
            $line = '[' . now()->format('d/m/Y') . "] {$reason}: pasang {$c->name} x{$qty}" . ($old ? ", lepas {$old->name} x{$oldQty}" : '');
            $a->specs = trim(($a->specs ? $a->specs . "\n" : '') . $line);
            $a->save();
        });
    }

    public function uninstall(Component $c, Asset $asset, int $qty, string $reason, ?string $note = null): void
    {
        DB::transaction(function () use ($c, $asset, $qty, $reason, $note) {
            $installed = $asset->installedComponents()->firstWhere(fn ($r) => $r['component']?->id === $c->id)['qty'] ?? 0;
            if ($qty > $installed) {
                throw new InvalidArgumentException("Komponen hanya terpasang {$installed} unit pada aset ini.");
            }
            app(StockService::class)->move($c, 'uninstall', $qty, ['asset_id' => $asset->id, 'reason' => $reason, 'notes' => $note]);
            $a = Asset::query()->lockForUpdate()->find($asset->id);
            $a->specs = trim(($a->specs ? $a->specs . "\n" : '') . '[' . now()->format('d/m/Y') . "] {$reason}: lepas {$c->name} x{$qty}");
            $a->save();
        });
    }
}
