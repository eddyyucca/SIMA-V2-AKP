<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\AssetService;
use Illuminate\Console\Command;

/** Samakan kelengkapan pada BAST pemegang dengan komponen yang terpasang sekarang (hanya BAST yang memang berbeda). */
class RefreshBastEquipment extends Command
{
    protected $signature = 'bast:refresh-equipment {--dry-run : Hanya tampilkan BAST yang kelengkapannya perlu diperbarui}';
    protected $description = 'Perbarui kelengkapan BAST pemegang yang tertinggal dari komponen terpasang (berkas lama dicadangkan ke bast-backup/)';

    public function handle(AssetService $assets): int
    {
        $n = 0;
        foreach (Asset::whereNotNull('employee_id')->with('employee')->orderBy('id')->get() as $asset) {
            if (! $assets->equipmentOutdated($asset)) continue;
            $a = $assets->currentHolderBast($asset);
            $now = $asset->installedComponents()->map(fn ($r) => $r['component']->name . ' ×' . (int) $r['qty'])->implode(', ') ?: '-';
            $this->line(($this->option('dry-run') ? '[rencana] ' : '[diperbarui] ') . "{$a->bast_number} · {$asset->asset_tag} · {$asset->employee->name} → kelengkapan: $now");
            if (! $this->option('dry-run')) $assets->refreshEquipment($asset);
            $n++;
        }
        $this->info(($this->option('dry-run') ? 'Rencana: ' : 'Selesai: ') . "$n BAST" . ($this->option('dry-run') ? ' perlu diperbarui.' : ' diperbarui.'));
        return self::SUCCESS;
    }
}
