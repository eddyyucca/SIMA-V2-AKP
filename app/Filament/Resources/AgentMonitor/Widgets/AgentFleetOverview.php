<?php

namespace App\Filament\Resources\AgentMonitor\Widgets;

use App\Services\AgentFleetMonitor;
use Filament\Widgets\Widget;

/** Ringkasan armada agent: kartu KPI + sebaran (edisi/versi Windows, versi agent, RAM). Diletakkan di atas tabel Ikhtisar. */
class AgentFleetOverview extends Widget
{
    protected static bool $isDiscovered = false;     // hanya dipakai di halaman Ikhtisar, bukan Dashboard
    protected static bool $isLazy = false;           // KPI langsung tampil, bukan placeholder
    protected string $view = 'filament.agent.fleet-overview';
    protected int|string|array $columnSpan = 'full';
    protected ?string $pollingInterval = '60s';

    protected function getViewData(): array
    {
        $d = app(AgentFleetMonitor::class)->build();

        return ['s' => $d['summary'], 'dist' => $d['distributions'], 'truncated' => $d['truncated'], 'latest' => $d['latest_agent_version']];
    }
}
