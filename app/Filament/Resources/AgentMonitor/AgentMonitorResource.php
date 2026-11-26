<?php

namespace App\Filament\Resources\AgentMonitor;

use App\Filament\Clusters\Agent;
use App\Filament\Resources\AgentMonitor\Pages\ListAgentMonitor;
use App\Filament\Resources\AgentMonitor\Pages\ViewAgentDevice;
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

/** Monitor armada SIMA Asset Agent: aset yang punya token / pernah lapor. Baca-saja; aksi per perangkat ada di detail aset. */
class AgentMonitorResource extends Resource
{
    protected static ?string $model = Asset::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;
    protected static ?string $cluster = Agent::class;
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'Perangkat Agent';
    protected static ?string $pluralModelLabel = 'Perangkat Agent';
    protected static ?string $navigationLabel = 'Ikhtisar';
    protected static ?string $slug = 'ikhtisar';

    public static function canAccess(): bool { return (bool) auth()->user()?->can('manage inventory'); }
    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        // Perangkat ber-agent, ditambah semua laptop yang belum terpasang agent (dulu tabel terpisah di halaman Rollout).
        $names = array_map('mb_strtoupper', (array) config('agent.laptop_categories', ['LAPTOP']));

        return parent::getEloquentQuery()->where(fn ($q) => $q->whereHas('agentHeartbeat')
            ->orWhereHas('agentTokens', fn ($t) => $t->whereNull('revoked_at'))
            ->orWhere(fn ($l) => $l->where('status', '!=', 'disposed')
                ->whereHas('category', fn ($c) => $c->whereIn(\DB::raw('upper(name)'), $names ?: ['LAPTOP']))))
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
                    ->formatStateUsing(fn ($state) => ['online' => 'Online', 'offline' => 'Offline', 'never_reported' => 'Belum lapor'][$state] ?? $state)
                    ->color(fn ($state) => ['online' => 'success', 'offline' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('agent_state')->label('Agent')->badge()
                    ->state(fn (Asset $a) => $a->agentHeartbeat ? 'installed' : ($a->agentTokens()->whereNull('revoked_at')->exists() ? 'token_only' : 'missing'))
                    ->formatStateUsing(fn ($state) => trans('agent.rollout_state_' . $state))
                    ->color(fn ($state) => ['installed' => 'success', 'token_only' => 'warning'][$state] ?? 'gray')->toggleable(),
                TextColumn::make('agent_last_seen')->label('Laporan terakhir')->since()->sortable(),
                TextColumn::make('ram')->label('RAM')->state(function (Asset $a) {
                    $hb = $a->agentHeartbeat;
                    if (! $hb?->ram_total_bytes) return null;
                    $used = $hb->ram_free_bytes !== null ? (int) round(($hb->ram_total_bytes - $hb->ram_free_bytes) / $hb->ram_total_bytes * 100) : null;
                    return number_format($hb->ram_total_bytes / 1073741824, 0) . ' GB' . ($used !== null ? " ({$used}%)" : '');
                })->placeholder('-')->toggleable(),
                TextColumn::make('disk')->label('Disk')->state(function (Asset $a) {
                    $d = $a->agentHeartbeat ? app(AgentAlertRules::class)->worstDiskUsage($a->agentHeartbeat) : null;
                    return $d ? "{$d['drive']} {$d['percent']}%" : null;
                })->placeholder('-')->badge()->color(function (Asset $a) {
                    $p = $a->agentHeartbeat ? (app(AgentAlertRules::class)->worstDiskUsage($a->agentHeartbeat)['percent'] ?? 0) : 0;
                    return $p >= 95 ? 'danger' : ($p >= 90 ? 'warning' : 'gray');
                })->toggleable(),
                TextColumn::make('gpu')->label('VGA')->state(function (Asset $a) {
                    $g = collect($a->agentHeartbeat?->gpus ?? []);
                    $main = $g->firstWhere('kind', 'discrete') ?? $g->first();   // utamakan kartu diskrit bila ada
                    return $main['name'] ?? null;
                })->placeholder('-')->limit(34)->tooltip(fn (Asset $a) => collect($a->agentHeartbeat?->gpus ?? [])->pluck('name')->implode(' · ') ?: null)->toggleable(),
                TextColumn::make('battery')->label('Baterai')->state(function (Asset $a) {
                    $hb = $a->agentHeartbeat;
                    return $hb && $hb->battery_present && $hb->battery_health_percent !== null ? $hb->battery_health_percent . '%' : null;
                })->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('security')->label('Keamanan')->state(function (Asset $a) {
                    $hb = $a->agentHeartbeat;
                    if (! $hb) return null;
                    $f = fn ($v) => $v === null ? '?' : ($v ? '✓' : '✗');
                    return 'BitLocker ' . ($hb->bitlocker_status === 'on' ? '✓' : ($hb->bitlocker_status === 'off' ? '✗' : '?')) . ' · AV ' . $f($hb->defender_enabled);
                })->placeholder('-')->toggleable(),
                TextColumn::make('updates')->label('Update')->state(fn (Asset $a) => $a->agentHeartbeat?->pending_updates_count)->placeholder('-')
                    ->suffix(fn ($state) => $state ? ' tertunda' : '')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('agentHeartbeat.agent_version')->label('Versi')->toggleable()
                    ->color(fn ($state) => $latest && (int) $state < $latest ? 'warning' : null),
                TextColumn::make('alerts')->label('Peringatan')->state(function (Asset $a) use ($latest) {
                    $al = app(AgentAlertRules::class)->evaluate($a, $a->agentHeartbeat, $latest);
                    $n = count(array_filter($al, fn ($x) => $x['severity'] !== 'info'));
                    return $n ? $n . ' perlu ditinjau' : 'OK';
                })->badge()->color(fn ($state) => $state === 'OK' ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('agent_status')->label('Status')->options(['online' => 'Online', 'offline' => 'Offline', 'never_reported' => 'Belum lapor']),
                SelectFilter::make('agent_state')->label('Pemasangan agent')->options(['installed' => 'Terpasang', 'token_only' => 'Token dibuat, belum lapor', 'missing' => 'Belum terpasang'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'installed' => $query->whereHas('agentHeartbeat'),
                        'token_only' => $query->whereDoesntHave('agentHeartbeat')->whereHas('agentTokens', fn ($t) => $t->whereNull('revoked_at')),
                        'missing' => $query->whereDoesntHave('agentHeartbeat')->whereDoesntHave('agentTokens', fn ($t) => $t->whereNull('revoked_at')),
                        default => $query,
                    }),
            ])
            ->recordActions([
                \App\Filament\Support\AgentActions::downloadAction(),
                Action::make('detail_agent')->label('Detail agent')->icon(Heroicon::OutlinedComputerDesktop)->url(fn (Asset $a) => self::getUrl('view', ['record' => $a])),
                Action::make('lihat')->label('Aset')->icon(Heroicon::OutlinedEye)->url(fn (Asset $a) => \App\Filament\Resources\Assets\AssetResource::getUrl('view', ['record' => $a])),
            ])
            ->defaultSort('agent_last_seen', 'desc')
            ->poll('60s');
    }

    public static function getPages(): array
    {
        return ['index' => ListAgentMonitor::route('/'), 'view' => ViewAgentDevice::route('/{record}')];
    }
}
