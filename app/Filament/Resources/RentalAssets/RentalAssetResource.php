<?php

namespace App\Filament\Resources\RentalAssets;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\RentalAssets\Pages\CreateRentalAsset;
use App\Filament\Resources\RentalAssets\Pages\ListRentalAssets;
use App\Models\Asset;
use App\Models\Vendor;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/** Menu terpisah untuk aset sewa (rental). Datanya tetap tabel assets; detail & edit memakai halaman Aset. */
class RentalAssetResource extends Resource
{
    protected static ?string $model = Asset::class;
    protected static ?string $slug = 'aset-sewa';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;
    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Aset Sewa (Rental)';
    protected static ?string $modelLabel = 'Aset Sewa';
    protected static ?string $pluralModelLabel = 'Aset Sewa (Rental)';
    protected static ?string $recordTitleAttribute = 'asset_tag';
    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])->where('ownership', 'rental');
    }

    /** Formulir sama dengan Aset; bagian sewa tampil otomatis pada halaman Tambah Aset Sewa. */
    public static function form(Schema $schema): Schema
    {
        return AssetResource::form($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['category', 'employee', 'vendor', 'location']))
            ->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('asset_tag')->label('Kode')->searchable()->sortable()->weight('bold'),
                TextColumn::make('brand')->label('Merek / Model')->formatStateUsing(fn ($state, $record) => trim("{$record->brand} {$record->model}"))->searchable(['brand', 'model']),
                TextColumn::make('serial_number')->label('Serial')->searchable()->toggleable(),
                TextColumn::make('vendor.name')->label('Penyedia sewa')->sortable(),
                TextColumn::make('rental_contract_no')->label('No. kontrak')->searchable()->toggleable(),
                TextColumn::make('rental_start')->label('Mulai')->date('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rental_end')->label('Berakhir')->date('d/m/Y')->sortable(),
                TextColumn::make('days_left')->label('Sisa')->badge()
                    ->state(fn ($record) => $record->status === 'returned' ? 'Dikembalikan'
                        : ($record->rental_days_left < 0 ? 'Lewat ' . abs($record->rental_days_left) . ' hr' : $record->rental_days_left . ' hari'))
                    ->color(fn ($record) => $record->status === 'returned' ? 'gray' : ($record->rental_days_left < 0 ? 'danger' : ($record->rental_days_left <= 30 ? 'warning' : 'success'))),
                TextColumn::make('rental_contract_status')->label('Status kontrak')->badge()
                    ->color(fn ($state) => match ($state) { 'Masa Aktif' => 'success', 'Expired' => 'danger', default => 'gray' }),
                TextColumn::make('rental_monthly_cost')->label('Biaya / bulan')->money('IDR', locale: 'id')->sortable()
                    ->summarize(Sum::make()->label('Total')->money('IDR', locale: 'id')),
                TextColumn::make('employee.name')->label('Pemegang')->searchable(),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state) => Asset::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'available' => 'success', 'deployed' => 'info', 'repair' => 'warning', 'borrowed', 'returned' => 'gray', default => 'danger' }),
                TextColumn::make('location.name')->label('Lokasi')->toggleable(isToggledHiddenByDefault: true),
            ])->defaultSort('rental_end')
            ->filters([
                Filter::make('active')->label('Hanya yang masih berjalan')->default()->query(fn ($query) => $query->where('status', '!=', 'returned')),
                SelectFilter::make('status')->label('Status')->options(Asset::STATUSES),
                SelectFilter::make('contract_status')->label('Status kontrak')->options(['active' => 'Masa Aktif', 'expired' => 'Expired'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'active' => $query->where('status', '!=', 'returned')->whereDate('rental_end', '>=', now()->toDateString()),
                        'expired' => $query->where('status', '!=', 'returned')->whereDate('rental_end', '<', now()->toDateString()),
                        default => $query,
                    }),
                SelectFilter::make('vendor_id')->label('Penyedia sewa')->options(fn () => Vendor::orderBy('name')->pluck('name', 'id')),
                Filter::make('expiring')->label('Berakhir ≤ 30 hari')->query(fn ($query) => $query->where('status', '!=', 'returned')->whereDate('rental_end', '<=', now()->addDays(30)->toDateString())),
                Filter::make('overdue')->label('Lewat jatuh tempo')->query(fn ($query) => $query->where('status', '!=', 'returned')->whereDate('rental_end', '<', now()->toDateString())),
            ])
            ->recordActions([
                ViewAction::make()->url(fn ($record) => static::getUrl('view', ['record' => $record])),
                EditAction::make()->url(fn ($record) => static::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkAction::make('labels_tape')->label('Label Brother (pita)')->icon(Heroicon::OutlinedPrinter)
                    ->action(function (Collection $records) {
                        $pdf = app(\App\Services\LabelService::class)->pdf('asset', $records, (int) config('labels.tape'), (int) config('labels.length'));
                        return response()->streamDownload(fn () => print($pdf->output()), 'label-aset-sewa.pdf');
                    })->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRentalAssets::route('/'),
            'create' => CreateRentalAsset::route('/create'),
            'view' => Pages\ViewRentalAsset::route('/{record}'),
            'edit' => Pages\EditRentalAsset::route('/{record}/edit'),
        ];
    }
}
