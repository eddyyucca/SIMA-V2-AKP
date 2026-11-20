<?php

namespace App\Console\Commands;

use App\Services\AgentRolloutReport;
use Illuminate\Console\Command;

/** Menyimpan foto harian pencapaian rollout agent (laptop total, terpasang, online) untuk grafik tren. Dijadwalkan harian; aman diulang (satu baris per hari). */
class AgentRolloutSnapshotCommand extends Command
{
    protected $signature = 'agent:rollout-snapshot';
    protected $description = 'Menyimpan foto harian pencapaian rollout SIMA Asset Agent';

    public function handle(AgentRolloutReport $report): int
    {
        $s = $report->snapshotToday();
        $this->info("Snapshot {$s->snapshot_date->toDateString()}: {$s->installed} dari {$s->laptops_total} laptop terpasang, {$s->online} online.");

        return self::SUCCESS;
    }
}
