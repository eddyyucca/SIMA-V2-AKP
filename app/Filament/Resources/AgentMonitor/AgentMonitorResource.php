<?php

namespace App\Filament\Resources\AgentMonitor;

use App\Filament\Resources\AgentMonitor\Pages\ListAgentMonitor;
use App\Models\Asset;
use App\Services\AgentAlertRules;
use App\Services\AgentUpdateManifest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Monitor armada SIMA Asset Agent: aset yang punya token / pernah lapor. Baca-saja; aksi per perangkat ada di detail aset. */
class AgentMonitorResource extends Resource
{
    protected static ?string $model = Asset::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;
    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';
    protected static ?int $navigationSort = 8;
    protected static ?string $modelLabel = 'Perangkat Agent';
    protected static ?string $pluralModelLabel = 'Monitor Agent';
    protected static ?string $navigationLabel = 'Monitor Agent';
    protected static ?string $slug = 'monitor-agent';

    public static function canAccess(): bool { return (bool) auth()->user()?->can('manage inventory'); }
    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where(fn ($q) => $q->whereHas('agentHeartbeat')->orWhereHas('agentTokens', fn ($t) => $t->whereNull('revoked_at')))
            ->with(['agentHeartbeat', 'employee']);
    }

    public static function table(Table $table): Table
    {
        $latest = app(AgentUpdateManifest::class)->latestVersion();

        return $table
            ->columns([
                TextColumn::make('asset_tag')->label('Aset')->searchable()->sortable(),
                TextColumn::make('employee.name')->label('Pemegang')->placeholder('-')->searchable(),
                TextColumn::make('agentHeartbeat.hostname')->label('Hostname')->searchable(),
                TextColumn::make('agentHeartbeat.ip_address')->label('IP'),
                TextColumn::make('agentHeartbeat.os_version')->label('OS')->toggleable(),
                TextColumn::make('agent_status')->label('Status')->badge()->sortable()
                    ->formatStateUsing(fn ($s) => ['online' => 'Online', 'offline' => 'Offline', 'never_reported' => 'Belum lapor'][$s] ?? $s)
                    ->color(fn ($s) => ['online' => 'success', 'offline' => 'danger'][$s] ?? 'gray'),
                TextColumn::make('agent_last_seen')->label('Laporan terakhir')->since()->sortable(),
                TextColumn::make('agentHeartbeat.agent_version')->label('Versi')->toggleable()
                    ->color(fn ($state) => $latest && (int) $state < $latest ? 'warning' : null),
                TextColumn::make('alerts')->label('Peringatan')->state(function (Asset $a) use ($latest) {
                    $al = app(AgentAlertRules::class)->evaluate($a, $a->agentHeartbeat, $latest);
                    $n = count(array_filter($al, fn ($x) => $x['severity'] !== 'info'));
                    return $n ? $n . ' perlu ditinjau' : 'OK';
                })->badge()->color(fn ($s) => $s === 'OK' ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('agent_status')->label('Status')->options(['online' => 'Online', 'offline' => 'Offline', 'never_reported' => 'Belum lapor']),
            ])
            ->recordActions([
                Action::make('lihat')->label('Detail aset')->icon(Heroicon::OutlinedEye)->url(fn (Asset $a) => \App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a])),
            ])
            ->defaultSort('agent_last_seen', 'desc')
            ->poll('60s');
    }

    public static function getPages(): array
    {
        return ['index' => ListAgentMonitor::route('/')];
    }
}
