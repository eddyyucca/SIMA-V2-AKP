<?php

namespace App\Filament\Resources\Disposals;

use App\Filament\Resources\Disposals\Pages\ManageDisposals;
use App\Filament\Support\Ui;
use App\Models\Asset;
use App\Models\Disposal;
use App\Services\DisposalService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class DisposalResource extends Resource
{
    protected static ?string $model = Disposal::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;
    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = 'Disposal';
    protected static ?string $pluralModelLabel = 'Disposal';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('asset_id')->label('Aset')->required()->searchable()
                ->options(fn () => Asset::active()->where('ownership', 'owned')->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->asset_tag} — {$a->brand} {$a->model}"])),
            Textarea::make('reason')->label('Alasan')->required(),
            Toggle::make('data_wiped')->label('Data pada perangkat sudah di-wipe (wajib)')->accepted()->validationMessages(['accepted' => 'Wipe data wajib sebelum pengajuan.']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['asset', 'requester', 'approver']))->columns([
            TextColumn::make('asset.asset_tag')->label('Aset')->searchable(),
            TextColumn::make('reason')->label('Alasan')->limit(40)->wrap(),
            IconColumn::make('data_wiped')->label('Wipe')->boolean(),
            TextColumn::make('method')->label('Metode')->formatStateUsing(fn ($state) => Disposal::METHODS[$state] ?? '-'),
            TextColumn::make('sale_price')->label('Harga jual')->money('IDR', locale: 'id')->placeholder('-')->toggleable(),
            TextColumn::make('gain_loss')->label('Laba/Rugi pelepasan')->toggleable()
                ->state(fn ($record) => $record->status === 'approved' && $record->asset
                    ? app(\App\Services\DepreciationService::class)->gainLoss($record->asset, (float) $record->sale_price) : null)
                ->money('IDR', locale: 'id')->placeholder('-')->color(fn ($state) => $state === null ? null : ($state >= 0 ? 'success' : 'danger')),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'][$state])
                ->color(fn ($state) => ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$state]),
            TextColumn::make('requester.name')->label('Pengaju'),
            TextColumn::make('approver.name')->label('Disetujui oleh')->toggleable(),
            TextColumn::make('created_at')->label('Diajukan')->date('d/m/Y')->sortable(),
        ])->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->options(['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'])])
            ->recordActions([
                Action::make('approve')->label('Setujui')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn ($record) => $record->status === 'pending' && auth()->user()->can('approve disposal'))
                    ->schema([
                        Select::make('method')->label('Metode disposal')->options(Disposal::METHODS)->required()->live(),
                        \Filament\Forms\Components\TextInput::make('sale_price')->label('Harga jual / hasil lelang')->numeric()->maxValue(100000000000)->prefix('Rp')->minValue(0)
                            ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => in_array($get('method'), ['sell', 'auction'], true))
                            ->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => in_array($get('method'), ['sell', 'auction'], true))
                            ->helperText('Dipakai untuk menghitung laba/rugi pelepasan terhadap nilai buku.'),
                    ])
                    ->action(fn (array $data, Disposal $record, Action $action) => Ui::run(fn () => app(DisposalService::class)->approve($record, $data['method'], isset($data['sale_price']) && $data['sale_price'] !== '' ? (float) $data['sale_price'] : null), 'Disposal disetujui, Berita Acara dibuat', $action)),
                Action::make('reject')->label('Tolak')->icon(Heroicon::OutlinedXMark)->color('danger')->requiresConfirmation()
                    ->visible(fn ($record) => $record->status === 'pending' && auth()->user()->can('approve disposal'))
                    ->action(fn (Disposal $record, Action $action) => Ui::run(fn () => app(DisposalService::class)->reject($record), 'Disposal ditolak', $action)),
                Action::make('ba_view')->label('Lihat BA')->icon(Heroicon::OutlinedEye)
                    ->visible(fn ($record) => (bool) $record->ba_pdf_path)
                    ->url(fn ($record) => route('disposal.ba.view', $record), shouldOpenInNewTab: true),
                Action::make('ba')->label('Unduh BA')->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->visible(fn ($record) => (bool) $record->ba_pdf_path)
                    ->url(fn ($record) => route('disposal.ba', $record), shouldOpenInNewTab: true),
            ]);
    }

    public static function canCreate(): bool { return auth()->user()?->can('manage inventory') ?? false; }

    public static function getPages(): array
    {
        return ['index' => ManageDisposals::route('/')];
    }
}
