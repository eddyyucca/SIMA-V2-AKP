<?php

namespace App\Console\Commands;

use App\Services\SimaImport\SimaImporter;
use App\Services\SimaImport\SimaReconciler;
use Illuminate\Console\Command;

class SimaReconcile extends Command
{
    protected $signature = 'sima:reconcile';
    protected $description = 'Bandingkan data SIMA (legacy) dengan aplikasi ini setelah impor. Exit code 1 bila ada selisih (gerbang cutover).';

    public function handle(SimaReconciler $r, SimaImporter $importer): int
    {
        $rows = $r->run($importer);
        $this->table(['Pemeriksaan', 'SIMA', 'Baru', 'Hasil'], collect($rows)->map(fn ($x) => [$x['check'], $x['legacy'], $x['new'], $x['ok'] ? 'OK' : 'SELISIH'])->all());
        $bad = collect($rows)->reject(fn ($x) => $x['ok']);
        if ($bad->isEmpty()) { $this->info('Rekonsiliasi BERSIH: tidak ada selisih.'); return self::SUCCESS; }
        $this->error($bad->count() . ' pemeriksaan selisih:');
        foreach ($bad as $x) $this->line("  - {$x['check']}: SIMA {$x['legacy']} vs baru {$x['new']} {$x['note']}");
        return self::FAILURE;
    }
}
