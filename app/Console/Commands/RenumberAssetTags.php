<?php

namespace App\Console\Commands;

use App\Support\AssetTagMigrator;
use Illuminate\Console\Command;

class RenumberAssetTags extends Command
{
    protected $signature = 'assets:renumber-tags {--dry-run : hanya tampilkan rencana, tidak mengubah apa pun}';
    protected $description = 'Rapikan kode aset ke format sekarang (awalan-kategori-nomor dengan jumlah digit tetap); kode lama disimpan agar label lama tetap bisa discan';

    public function handle(): int
    {
        $plan = AssetTagMigrator::plan();
        if (! $plan) {
            $this->info('Semua kode aset sudah sesuai format. Tidak ada yang diubah.');
            return self::SUCCESS;
        }
        $this->table(['ID', 'Kode lama', 'Kode baru', 'Jenis'], array_map(fn ($p) => [$p['id'], $p['old'], $p['new'], $p['pad'] ? 'tambah nol' : 'nomor baru'], $plan));
        if ($this->option('dry-run')) {
            $this->warn(count($plan) . ' aset akan berubah (dry-run: tidak ada yang disimpan).');
            return self::SUCCESS;
        }
        $n = AssetTagMigrator::renumber();
        $this->info("{$n} kode aset diperbarui; kode lama tersimpan di riwayat (agar label lama tetap bisa discan).");
        return self::SUCCESS;
    }
}
