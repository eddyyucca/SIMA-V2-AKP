<?php

namespace App\Filament\Resources\AgentMonitor\Pages;

use App\Filament\Resources\AgentMonitor\AgentMonitorResource;
use App\Filament\Resources\AgentMonitor\Widgets\AgentFleetOverview;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAgentMonitor extends ListRecords
{
    protected static string $resource = AgentMonitorResource::class;

    public function getTitle(): string { return trans('agent.monitor_title'); }

    protected function getHeaderWidgets(): array { return [AgentFleetOverview::class]; }

    public function getTabs(): array
    {
        return [
            'semua' => Tab::make(trans('agent.filter_all_devices')),
            'online' => Tab::make(trans('general.agent_status_online') === 'general.agent_status_online' ? 'Online' : trans('general.agent_status_online'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('agent_status', 'online')),
            'offline' => Tab::make('Offline')->modifyQueryUsing(fn (Builder $query) => $query->where('agent_status', 'offline')),
            'belum' => Tab::make(trans('agent.kpi_never'))->modifyQueryUsing(fn (Builder $query) => $query->where('agent_status', 'never_reported')),
        ];
    }
}
