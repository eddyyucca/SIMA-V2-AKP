<?php

namespace App\Filament\Resources\AgentAnnouncements;

use App\Filament\Clusters\Agent;
use App\Filament\Resources\AgentAnnouncements\Pages\ManageAgentAnnouncements;
use App\Models\AgentAnnouncement;
use App\Services\AgentAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Pengumuman yang ditampilkan agent di laptop pengguna. Hanya Admin. */
class AgentAnnouncementResource extends Resource
{
    protected static ?string $model = AgentAnnouncement::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;
    protected static ?string $cluster = Agent::class;
    protected static ?int $navigationSort = 5;
    protected static ?string $modelLabel = 'Pengumuman Agent';
    protected static ?string $pluralModelLabel = 'Pengumuman Agent';
    protected static ?string $navigationLabel = 'Pengumuman';
    protected static ?string $slug = 'pengumuman-agent';

    public static function canAccess(): bool { return (bool) auth()->user()?->hasRole('Admin'); }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Judul')->required()->maxLength(AgentAnnouncement::TITLE_MAX),
            Textarea::make('body')->label('Isi')->required()->rows(4)->maxLength(AgentAnnouncement::BODY_MAX),
            Select::make('severity')->label('Tingkat')->required()->default('info')
                ->options(['info' => 'Info', 'important' => 'Penting', 'urgent' => 'Mendesak']),
            Toggle::make('require_ack')->label('Wajib dikonfirmasi pengguna (muncul terus sampai dikonfirmasi)'),
            Select::make('target_mode')->label('Sasaran')->required()->default('all')->live()
                ->options(['all' => 'Semua perangkat ber-agent', 'selected' => 'Perangkat tertentu']),
            Select::make('asset_ids')->label('Perangkat')->multiple()->searchable()
                ->visible(fn ($get) => $get('target_mode') === 'selected')
                ->options(fn () => app(AgentAdminService::class)->agentDevices()->orderBy('asset_tag')->pluck('asset_tag', 'assets.id')),
            DateTimePicker::make('starts_at')->label('Mulai tampil'),
            DateTimePicker::make('ends_at')->label('Berakhir')->after('starts_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(AgentAnnouncement::query()->withCount([
                'targets as targets_count',
                'deliveries as shown_count' => fn ($q) => $q->whereNotNull('shown_at'),
                'deliveries as acked_count' => fn ($q) => $q->whereNotNull('acked_at'),
            ])->latest('id'))
            ->columns([
                TextColumn::make('title')->label('Judul')->searchable(),
                TextColumn::make('severity')->label('Tingkat')->badge()->color(fn ($state) => ['urgent' => 'danger', 'important' => 'warning'][$state] ?? 'gray'),
                TextColumn::make('state')->label('Status')->state(fn (AgentAnnouncement $a) => $a->state())->badge(),
                TextColumn::make('target_mode')->label('Sasaran')->formatStateUsing(fn ($state, $record) => $state === 'all' ? 'Semua' : $record->targets_count . ' perangkat'),
                TextColumn::make('shown_count')->label('Tampil'),
                TextColumn::make('acked_count')->label('Konfirmasi'),
                TextColumn::make('created_at')->label('Dibuat')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('stop')->label('Hentikan')->icon(Heroicon::OutlinedStopCircle)->requiresConfirmation()
                    ->visible(fn (AgentAnnouncement $a) => in_array($a->state(), ['live', 'scheduled'], true))
                    ->action(function (AgentAnnouncement $a) {
                        app(AgentAdminService::class)->stopAnnouncement($a);
                        Notification::make()->title(trans('agent.ann_stopped'))->success()->send();
                    }),
                Action::make('hapus')->label('Hapus')->icon(Heroicon::OutlinedTrash)->color('danger')->requiresConfirmation()
                    ->action(function (AgentAnnouncement $a) {
                        app(AgentAdminService::class)->deleteAnnouncement($a);
                        Notification::make()->title(trans('agent.ann_deleted'))->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAgentAnnouncements::route('/')];
    }
}
