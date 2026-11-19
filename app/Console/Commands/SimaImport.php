<?php

namespace App\Console\Commands;

use App\Services\SimaImport\SimaImporter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SimaImport extends Command
{
    protected $signature = 'sima:import {--dry-run : Jalankan semuanya lalu BATALKAN (rollback); hanya laporan} {--only=* : Hanya langkah tertentu (departments,locations,vendors,categories,models,employees,assets,assignments,stock,licenses,repairs,agent,files)} {--files= : Folder berkas SIMA (foto aset, PDF BAST) untuk disalin}';
    protected $description = 'Impor data SIMA (koneksi "legacy", hanya baca) ke aplikasi ini. Idempoten; aman diulang.';

    public function handle(SimaImporter $importer): int
    {
        try { DB::connection('legacy')->getPdo(); }
        catch (\Throwable $e) { $this->error('Tidak bisa membaca database SIMA (koneksi "legacy"): ' . $e->getMessage() . "\nIsi LEGACY_DB_* di .env."); return self::FAILURE; }

        $dry = (bool) $this->option('dry-run');
        $importer->setFilesPath($this->option('files'));
        $this->info($dry ? 'SIMULASI (semua perubahan dibatalkan di akhir)' : 'IMPOR NYATA');

        DB::beginTransaction();
        try {
            Model::withoutEvents(fn () => $importer->run($this->option('only')));
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Impor GAGAL dan dibatalkan seluruhnya: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
            return self::FAILURE;
        }

        ksort($importer->stats);
        $this->table(['Hasil', 'Jumlah'], collect($importer->stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        if ($importer->unknownStatuses) {
            $this->warn('Status SIMA tak dikenal (dipetakan ke Tersedia/Dipakai; tambahkan ke config/sima_import.php > status_overrides):');
            foreach ($importer->unknownStatuses as $n => $c) $this->line("  - {$n}: {$c} aset");
        }
        $this->line(count($importer->warnings) . ' peringatan, ' . count($importer->unmatched) . ' pengguna perlu dilengkapi NIK-nya.');

        $dir = 'sima-import/' . now()->format('Ymd-His') . ($dry ? '-dry' : '');
        Storage::disk('local')->put("$dir/laporan.json", json_encode(['stats' => $importer->stats, 'unknown_statuses' => $importer->unknownStatuses, 'warnings' => $importer->warnings], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($importer->unmatched) {
            $csv = "sima_user_id,username,name,email,reason\n" . collect($importer->unmatched)->map(fn ($r) => collect($r)->map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"')->implode(','))->implode("\n");
            Storage::disk('local')->put("$dir/pengguna-tanpa-nik.csv", $csv);
        }
        $this->info('Laporan: ' . storage_path('app/private/' . $dir));

        return self::SUCCESS;
    }
}
