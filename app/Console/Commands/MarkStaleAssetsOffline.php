<?php

namespace App\Console\Commands;

use App\Models\Asset;
use Illuminate\Console\Command;

/**
 * SIMA Asset Agent (custom SIMA): aset yang berstatus 'online' tapi belum lapor lagi dalam --minutes menit
 * ditandai 'offline'. Dijadwalkan tiap 5 menit (lihat app/Console/Kernel.php).
 */
class MarkStaleAssetsOffline extends Command
{
    protected $signature = 'agent:mark-stale-offline {--minutes=15 : Tandai offline jika belum lapor sejak sekian menit}';

    protected $description = 'Menandai aset SIMA Asset Agent sebagai offline jika belum heartbeat baru-baru ini';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));

        $count = Asset::where('agent_status', 'online')
            ->where('agent_last_seen', '<', now()->subMinutes($minutes))
            ->update(['agent_status' => 'offline']);

        $this->info("{$count} aset ditandai offline (tidak lapor > {$minutes} menit).");

        return self::SUCCESS;
    }
}
