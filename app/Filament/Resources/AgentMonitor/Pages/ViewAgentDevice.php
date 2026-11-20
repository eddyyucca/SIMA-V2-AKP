<?php

namespace App\Filament\Resources\AgentMonitor\Pages;

use App\Filament\Resources\AgentMonitor\AgentMonitorResource;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Support\AgentActions;
use App\Models\AssetAgentCommand;
use App\Services\AgentAlertRules;
use App\Services\AgentUpdateManifest;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Detail lengkap satu perangkat ber-agent: ringkasan, prosesor & memori, penyimpanan, jaringan, keamanan, software, aksi/chat, dan riwayat perubahan. */
class ViewAgentDevice extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AgentMonitorResource::class;
    protected string $view = 'filament.agent.device';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return $this->record->asset_tag . ' — ' . trans('agent.page_title');
    }

    public function getBreadcrumbs(): array
    {
        return [AgentMonitorResource::getUrl() => trans('agent.monitor_title'), '#' => $this->record->asset_tag];
    }

    protected function getHeaderActions(): array
    {
        $record = $this->record;
        return [
            Action::make('aset')->label(trans('agent.back_to_asset'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                ->url(AssetResource::getUrl('view', ['record' => $record])),
            ...AgentActions::make(fn () => $this->record->fresh()),
        ];
    }

    protected function getViewData(): array
    {
        $a = $this->record->loadMissing(['employee.department', 'location', 'category']);
        $hb = $a->agentHeartbeat()->first();
        $latest = app(AgentUpdateManifest::class)->latestVersion();

        return [
            'a' => $a,
            'hb' => $hb,
            'alerts' => app(AgentAlertRules::class)->evaluate($a, $hb, $latest),
            'latest' => $latest,
            'disk' => $hb ? app(AgentAlertRules::class)->worstDiskUsage($hb) : null,
            'changes' => $a->specChanges()->limit(100)->get(),
            'changesTotal' => $a->specChanges()->count(),
            'commands' => $a->agentCommands()->with('requester')->limit(30)->get(),
            'chatOpen' => $a->chatSessions()->open()->first(),
            'chatHistory' => $a->chatSessions()->withCount('messages')->whereNotNull('closed_at')->limit(5)->get(),
            'tokenActive' => $a->agentTokens()->whereNull('revoked_at')->exists(),
        ];
    }
}
