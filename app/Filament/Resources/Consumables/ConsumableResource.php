<?php

namespace App\Filament\Resources\Consumables;

use App\Filament\Resources\Consumables\Pages\ManageConsumables;
use App\Filament\Support\Ui;
use App\Filament\Support\WithTrashed;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Consumable;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Vendor;
use App\Services\StockService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use UnitEnum;

class ConsumableResource extends Resource
{
    use WithTrashed;

    protected static ?string $model = Consumable::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;
    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';
    protected static ?int $navigationSort = 4;
    protected static ?string $modelLabel = 'Consumable';
    protected static ?string $pluralModelLabel = 'Consumable';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama')->required(),
            \App\Filament\Support\QuickAdd::category(Select::make('category_id')->label('Kategori')->options(fn () => Category::where('type', 'consumable')->pluck('name', 'id')), 'consumable'),
            Select::make('unit')->label('Satuan')->options(fn () => Consumable::unitOptions())->default('pcs')->required()->searchable()
                ->createOptionForm([TextInput::make('name')->label('Nama satuan')->required()->maxLength(30)->placeholder('mis. lembar, botol, set')])
                ->createOptionUsing(fn (array $data) => Consumable::addUnit($data['name'])),
            TextInput::make('compatible_with')->label('Kompatibel untuk')->helperText('Contoh: HP LaserJet P1102, M1132'),
            TextInput::make('qty')->label('Stok awal')->numeric()->default(0)->minValue(0)->maxValue(1000000)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0)->disabledOn('edit')
                ->helperText('Stok diubah lewat aksi Stok Masuk / Keluarkan.'),
            TextInput::make('min_qty')->label('Stok minimum')->numeric()->default(0)->minValue(0)->maxValue(1000000)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0),
            \App\Filament\Support\QuickAdd::location(Select::make('location_id')->label('Lokasi')->options(fn () => Location::pluck('name', 'id'))),
            TextInput::make('unit_price')->label('Harga satuan (pembelian awal)')->numeric()->prefix('Rp')->default(0)->minValue(0)->maxValue(100000000000)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0)->disabledOn('edit')
                ->helperText('Setelah dibuat, harga & vendor terakhir diperbarui otomatis dari tiap pembelian (aksi Stok Masuk).'),
            \App\Filament\Support\QuickAdd::vendor(Select::make('vendor_id')->label('Vendor (pembelian awal)')->options(fn () => Vendor::pluck('name', 'id'))->disabledOn('edit')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->headerActions(\App\Filament\Support\StockToolbar::actions(\App\Models\Consumable::class))->columns([
            TextColumn::make('name')->label('Nama')->searchable()->sortable(),
            TextColumn::make('compatible_with')->label('Kompatibel untuk')->searchable()->wrap(),
            TextColumn::make('qty')->label('Stok')->numeric(decimalPlaces: 0)->badge()
                ->color(fn ($record) => $record->isLow() ? 'danger' : 'success')
                ->suffix(fn ($record) => ' ' . $record->unit),
            TextColumn::make('min_qty')->label('Min')->numeric(decimalPlaces: 0),
            TextColumn::make('location.name')->label('Lokasi'),
            TextColumn::make('vendor.name')->label('Vendor terakhir')->toggleable(),
            TextColumn::make('unit_price')->label('Harga terakhir')->money('IDR', locale: 'id')->toggleable(),
            TextColumn::make('average_price')->label('Harga rata-rata')->money('IDR', locale: 'id')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('stock_value')->label('Nilai stok')->money('IDR', locale: 'id')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Filter::make('low')->label('Stok ≤ minimum')->query(fn ($query) => $query->whereColumn('qty', '<=', 'min_qty')),
            TrashedFilter::make(),
        ])->recordActions([
            \App\Filament\Support\StockInAction::make(false),
            \App\Filament\Support\StockInAction::history(),
            \App\Filament\Support\StockHeaderActions::rowStockOut(\App\Models\Consumable::class),
            EditAction::make(), DeleteAction::make(), RestoreAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageConsumables::route('/')];
    }
}
