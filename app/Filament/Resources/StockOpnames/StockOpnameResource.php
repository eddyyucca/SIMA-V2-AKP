<?php

namespace App\Filament\Resources\StockOpnames;

use App\Filament\Pages\OpnameSession;
use App\Filament\Resources\StockOpnames\Pages\ManageStockOpnames;
use App\Models\Location;
use App\Models\StockOpname;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class StockOpnameResource extends Resource
{
    protected static ?string $model = StockOpname::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;
    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';
    protected static ?int $navigationSort = 4;
    protected static ?string $modelLabel = 'Stock Opname';
    protected static ?string $pluralModelLabel = 'Stock Opname';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            \App\Filament\Support\QuickAdd::location(Select::make('location_id')->label('Lokasi')->required()->options(fn () => Location::pluck('name', 'id'))),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['location', 'officer'])->withCount([
            'items as found_count' => fn ($query) => $query->where('result', 'found'),
            'items as wrong_count' => fn ($query) => $query->where('result', 'wrong_location'),
            'items as missing_count' => fn ($query) => $query->where('result', 'missing'),
        ]))->columns([
            TextColumn::make('id')->label('#')->sortable(),
            TextColumn::make('location.name')->label('Lokasi'),
            TextColumn::make('started_at')->label('Mulai')->dateTime('d/m/Y H:i')->sortable(),
            TextColumn::make('officer.name')->label('Petugas'),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state === 'running' ? 'Berjalan' : 'Selesai')->color(fn ($state) => $state === 'running' ? 'warning' : 'success'),
            TextColumn::make('found_count')->label('Ditemukan'),
            TextColumn::make('wrong_count')->label('Lokasi beda'),
            TextColumn::make('missing_count')->label('Tidak ditemukan'),
        ])->defaultSort('id', 'desc')->recordActions([
            Action::make('scan')->label(fn ($record) => $record?->status === 'running' ? 'Scan' : 'Lihat')->icon(Heroicon::OutlinedQrCode)
                ->url(fn ($record) => OpnameSession::getUrl(['opname' => $record->id])),
            Action::make('xlsx')->label('Excel')->icon(Heroicon::OutlinedTableCells)->visible(fn ($record) => $record->status === 'finished')
                ->url(fn ($record) => route('reports.download', ['key' => 'opname', 'format' => 'xlsx', 'opname' => $record->id])),
            Action::make('pdf')->label('PDF')->icon(Heroicon::OutlinedDocumentText)->visible(fn ($record) => $record->status === 'finished')
                ->url(fn ($record) => route('reports.download', ['key' => 'opname', 'format' => 'pdf', 'opname' => $record->id])),
        ]);
    }

    public static function canCreate(): bool { return auth()->user()?->can('manage inventory') ?? false; }

    public static function getPages(): array
    {
        return ['index' => ManageStockOpnames::route('/')];
    }
}
