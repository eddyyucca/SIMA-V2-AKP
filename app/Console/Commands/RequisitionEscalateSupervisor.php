<?php

namespace App\Console\Commands;

use App\Services\RequestSupervisor;
use Illuminate\Console\Command;

class RequisitionEscalateSupervisor extends Command
{
    protected $signature = 'requisition:escalate-supervisor';
    protected $description = 'Teruskan ke Tim IT request yang menunggu persetujuan atasan lebih dari batas hari';

    public function handle(RequestSupervisor $svc): int
    {
        $n = $svc->escalate();
        $this->info("{$n} request diteruskan ke Tim IT.");

        return self::SUCCESS;
    }
}
