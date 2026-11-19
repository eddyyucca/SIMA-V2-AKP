<?php

namespace App\Console\Commands;

use App\Services\ComponentInstallBackfill;
use Illuminate\Console\Command;

class BackfillComponentInstallDocuments extends Command
{
    protected $signature = 'components:backfill-install-documents {--dry-run : Hanya tampilkan rencana, tidak menyimpan apa pun}';
    protected $description = 'Buat dokumen susulan untuk pemasangan / pelepasan komponen lama yang belum punya dokumen (stok tidak diubah, aman diulang)';

    public function handle(ComponentInstallBackfill $backfill): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = $backfill->run($dry);
        foreach ($r['documents'] as $d) $this->line(($dry ? '[rencana] ' : '[dibuat]  ') . $d);
        $this->info(($dry ? 'Rencana: ' : 'Selesai: ') . "{$r['created']} dokumen" . ($dry ? ' akan dibuat' : ' dibuat') . ", {$r['skipped']} dilewati.");
        return self::SUCCESS;
    }
}
