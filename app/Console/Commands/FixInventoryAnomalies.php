<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Repair;
use Illuminate\Console\Command;

/**
 * Memperbaiki anomali data yang AMAN dilengkapi (tidak ada yang dihapus atau diubah statusnya):
 *  - aset berstatus Perbaikan tetapi tanpa tiket perbaikan terbuka (hasil impor SIMA) -> dibuatkan tiket "sedang dikerjakan" supaya
 *    muncul di daftar Perbaikan dan bisa ditutup oleh IT.
 * Tanpa --apply hanya menampilkan rencana.
 */
class FixInventoryAnomalies extends Command
{
    protected $signature = 'inventory:fix-anomalies {--apply : jalankan perbaikan (default: hanya tampilkan)}';
    protected $description = 'Lengkapi data yang tidak konsisten secara aman (tidak menghapus apa pun)';

    public function handle(): int
    {
        $assets = Asset::where('status', 'repair')->whereDoesntHave('repairs', fn ($q) => $q->where('status', '!=', 'done'))->orderBy('asset_tag')->get();
        $this->line("Aset berstatus Perbaikan tanpa tiket terbuka: {$assets->count()}");

        foreach ($assets as $a) {
            $this->line(' - ' . $a->asset_tag . ' ' . trim(($a->brand ?? '') . ' ' . ($a->model ?? '')));
            if (! $this->option('apply')) continue;
            Repair::create([
                'asset_id' => $a->id, 'reported_at' => $a->updated_at ?? now(), 'status' => 'in_progress',
                'issue' => 'Status Perbaikan dibawa dari SIMA; tiket perbaikan belum tercatat di sistem ini. Lengkapi atau tutup tiket ini.',
            ]);
        }

        $this->info($this->option('apply') ? "Tiket dibuat: {$assets->count()}" : 'Simulasi saja. Tambahkan --apply untuk membuat tiket.');

        return self::SUCCESS;
    }
}
