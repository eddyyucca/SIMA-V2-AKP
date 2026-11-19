<?php

namespace App\Console\Commands;

use App\Models\AssetAssignment;
use App\Services\AssetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Ubah nomor BAST aset berformat lama (BAST/2026/11/0053) menjadi IT/BAST/2026/11/0053: nomor di database, nama berkas PDF, dan isi PDF
 * (dibuat ulang dengan nomor baru). Berkas lama dicadangkan ke bast-backup/ dan tidak dihapus tanpa cadangan. Aman dijalankan berulang.
 */
class RenumberAssetBast extends Command
{
    protected $signature = 'bast:renumber-assets {--dry-run : hanya tampilkan rencana, tidak mengubah apa pun}';
    protected $description = 'Ubah nomor BAST aset ke format IT/BAST/TAHUN/BULAN/URUT (berkas lama dicadangkan ke bast-backup/)';

    /** BAST/2026/11/0053 -> IT/BAST/2026/11/0053 */
    private function convert(string $legacy): string
    {
        return AssetAssignment::NUMBER_PREFIX . substr($legacy, strlen(AssetAssignment::LEGACY_NUMBER_PREFIX));
    }

    public function handle(AssetService $assets): int
    {
        $legacy = AssetAssignment::where('bast_number', 'like', AssetAssignment::LEGACY_NUMBER_PREFIX . '%')->orderBy('id')->get();
        if ($legacy->isEmpty()) {
            $this->info('Semua nomor BAST aset sudah berformat IT/BAST/…. Tidak ada yang diubah.');
            return self::SUCCESS;
        }
        $rows = [];
        foreach ($legacy as $a) {
            $new = $this->convert($a->bast_number);
            $clash = AssetAssignment::where('bast_number', $new)->exists();
            $rows[] = [$a->id, $a->bast_number, $new, $a->bast_pdf_path ?: '-', $clash ? 'BENTROK' : 'ok'];
        }
        $this->table(['ID', 'Nomor lama', 'Nomor baru', 'Berkas PDF lama', 'Cek'], $rows);
        if (collect($rows)->contains(fn ($r) => $r[4] !== 'ok')) {
            $this->error('Ada nomor baru yang sudah dipakai; tidak ada yang diubah.');
            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->warn(count($rows) . ' BAST akan diubah (dry-run: tidak ada yang disimpan).');
            return self::SUCCESS;
        }

        $disk = Storage::disk('local');
        $n = 0;
        foreach ($legacy as $a) {
            $old = $a->bast_pdf_path;
            $backup = null;
            if ($old && $disk->exists($old)) {
                $backup = 'bast-backup/' . pathinfo($old, PATHINFO_FILENAME) . '-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.pdf';
                $disk->copy($old, $backup);                       // cadangan dulu, baru ubah apa pun
            }
            DB::transaction(function () use ($a, $assets) {
                $a->update(['bast_number' => $this->convert($a->bast_number)]);
                $assets->makeBast($a->fresh());                   // PDF baru dengan nomor baru; bast_pdf_path ikut diperbarui
            });
            $fresh = $a->fresh();
            if ($old && $old !== $fresh->bast_pdf_path && $disk->exists($fresh->bast_pdf_path) && $backup) {
                $disk->delete($old);                              // berkas lama sudah punya cadangan; tidak ada berkas yatim
            }
            $this->line("  {$fresh->bast_number} -> {$fresh->bast_pdf_path}" . ($backup ? "  (cadangan: $backup)" : ''));
            $n++;
        }
        $this->info("{$n} nomor BAST aset diubah ke format IT/BAST/….");
        return self::SUCCESS;
    }
}
