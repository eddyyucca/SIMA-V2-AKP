<?php

namespace App\Console\Commands;

use App\Models\AssetAssignment;
use App\Services\AssetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Buat ulang PDF BAST aset yang berkasnya rusak / tertimpa. Berkas lama dicadangkan dulu ke bast-backup/ dan tidak pernah dihapus.
 * Isi dibuat dari data BAST tersebut (pihak, tanggal, nomor) dengan tata letak terbaru; kelengkapan aset mengikuti keadaan saat BAST dibuat.
 */
class RegenerateAssetBast extends Command
{
    protected $signature = 'bast:regenerate {numbers* : Nomor BAST, mis. IT/BAST/2026/11/0032} {--dry-run : Hanya tampilkan yang akan dibuat ulang}';
    protected $description = 'Buat ulang PDF BAST aset yang tertimpa / rusak (berkas lama dicadangkan ke bast-backup/)';

    public function handle(AssetService $assets): int
    {
        $failed = 0;
        foreach ((array) $this->argument('numbers') as $no) {
            $a = AssetAssignment::where('bast_number', $no)->first();
            if (! $a) { $this->error("$no: tidak ditemukan"); $failed++; continue; }
            if ($this->option('dry-run')) { $this->line("[rencana] $no ({$a->type}) {$a->bast_pdf_path}"); continue; }
            $disk = Storage::disk('local');
            if ($a->bast_pdf_path && $disk->exists($a->bast_pdf_path)) {
                $backup = 'bast-backup/' . pathinfo($a->bast_pdf_path, PATHINFO_FILENAME) . '-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.pdf';
                $disk->copy($a->bast_pdf_path, $backup);
                $this->line("  cadangan berkas lama: $backup");
            }
            $assets->makeBast($a->fresh());
            $this->info("[dibuat ulang] $no -> " . $a->fresh()->bast_pdf_path);
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
