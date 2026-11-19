<?php

namespace App\Filament\Resources\AssetAssignments;

use App\Filament\Resources\AssetAssignments\Pages\ManageAssetAssignments;
use App\Models\Asset;
use App\Models\AssetAssignment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AssetAssignmentResource extends Resource
{
    protected static ?string $model = AssetAssignment::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;
    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'Serah Terima Aset (BAST)';
    protected static ?string $pluralModelLabel = 'Serah Terima Aset (BAST)';

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['asset', 'from', 'to', 'creator', 'bastFiles']))->columns([
            TextColumn::make('bast_number')->label('No. BAST')->searchable()->sortable(),
            TextColumn::make('assigned_at')->label('Tanggal')->dateTime('d/m/Y H:i')->sortable(),
            TextColumn::make('type')->label('Jenis')->badge()->formatStateUsing(fn ($state) => AssetAssignment::TYPES[$state]),
            TextColumn::make('asset.asset_tag')->label('Aset')->searchable(),
            TextColumn::make('from.name')->label('Dari')->placeholder('Gudang IT'),
            TextColumn::make('to.name')->label('Kepada')->placeholder('Gudang IT'),
            TextColumn::make('condition')->label('Kondisi')->formatStateUsing(fn ($state) => Asset::CONDITIONS[$state] ?? $state),
            TextColumn::make('creator.name')->label('Dicatat oleh')->toggleable(),
        ])->defaultSort('assigned_at', 'desc')
            ->filters([SelectFilter::make('type')->options(AssetAssignment::TYPES)])
            ->recordActions([
                Action::make('bast_view')->label('Lihat')->icon(Heroicon::OutlinedEye)
                    ->url(fn ($record) => route('bast.view', $record), shouldOpenInNewTab: true),
                Action::make('bast_signed')->label('Scan TTD')->icon(Heroicon::OutlinedDocumentCheck)
                    ->visible(fn ($record) => $record->bastFiles->isNotEmpty())
                    ->url(fn ($record) => route('bast.file', $record->bastFiles->sortByDesc('id')->first()), shouldOpenInNewTab: true),
                Action::make('bast')->label('Unduh BAST')->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn ($record) => route('bast.download', $record), shouldOpenInNewTab: true),
            ]);
    }

    public static function canCreate(): bool { return false; }

    public static function getPages(): array
    {
        return ['index' => ManageAssetAssignments::route('/')];
    }
}
