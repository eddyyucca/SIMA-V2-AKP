<?php

namespace App\Console\Commands;

use App\Models\AssetAssignment;
use App\Services\AssetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Membuat PDF BAST yang belum ada (serah terima hasil impor SIMA tidak membawa PDF) dari data serah terima. Idempoten: yang sudah punya PDF
 * dilewati; tidak pernah menimpa berkas yang ada. Tanpa perintah ini PDF tetap dibuat otomatis saat pertama dibuka.
 */
class BastGenerateMissing extends Command
{
    protected $signature = 'bast:generate-missing {--limit=0 : maksimum yang dibuat (0 = semua)} {--dry-run : hanya hitung}';
    protected $description = 'Buat PDF BAST yang belum ada dari data serah terima (tidak menimpa yang sudah ada)';

    public function handle(AssetService $svc): int
    {
        $disk = Storage::disk('local');
        $limit = (int) $this->option('limit');
        [$made, $failed, $skipped] = [0, 0, 0];

        foreach (AssetAssignment::query()->lazyById(50) as $a) {
            if ($a->bast_pdf_path && $disk->exists($a->bast_pdf_path)) { $skipped++; continue; }
            if ($this->option('dry-run')) { $made++; continue; }
            try {
                $svc->makeBast($a);
                $made++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("BAST {$a->bast_number}: " . substr($e->getMessage(), 0, 120));
            }
            if ($limit > 0 && $made >= $limit) break;
        }

        $this->info(($this->option('dry-run') ? '[simulasi] ' : '') . "dibuat: {$made}, sudah ada: {$skipped}, gagal: {$failed}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
