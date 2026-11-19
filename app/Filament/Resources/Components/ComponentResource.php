<?php

namespace App\Filament\Resources\Components;

use App\Filament\Resources\Components\Pages\ManageComponents;
use App\Filament\Support\Ui;
use App\Filament\Support\WithTrashed;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Component;
use App\Models\Location;
use App\Models\Vendor;
use App\Services\ComponentService;
use App\Services\StockService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
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

class ComponentResource extends Resource
{
    use WithTrashed;

    protected static ?string $model = Component::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;
    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';
    protected static ?int $navigationSort = 5;
    protected static ?string $modelLabel = 'Komponen';
    protected static ?string $pluralModelLabel = 'Komponen';
    protected static ?string $recordTitleAttribute = 'name';

    public static function assetOptions(): array
    {
        return Asset::active()->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->asset_tag} — {$a->brand} {$a->model}"])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama')->required(),
            \App\Filament\Support\QuickAdd::category(Select::make('category_id')->label('Kategori')->options(fn () => Category::where('type', 'component')->pluck('name', 'id')), 'component'),
            Textarea::make('specs')->label('Spesifikasi'),
            TextInput::make('serial_number')->label('Serial number (opsional)')->maxLength(255)
                ->rule(fn (?\Illuminate\Database\Eloquent\Model $record) => new \App\Rules\UniqueSerialNumber($record))
                ->helperText('Tidak boleh sama dengan aset atau komponen lain.'),
            TextInput::make('qty_total')->label('Stok awal')->numeric()->integer()->default(0)->minValue(0)->maxValue(1000000)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0)->disabledOn('edit')
                ->helperText('Stok diubah lewat aksi Stok Masuk / Pasang.'),
            TextInput::make('min_qty')->label('Stok minimum')->numeric()->integer()->default(0)->minValue(0)->maxValue(1000000)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0),
            \App\Filament\Support\QuickAdd::location(Select::make('location_id')->label('Lokasi')->options(fn () => Location::pluck('name', 'id'))),
            TextInput::make('price')->label('Harga (pembelian awal)')->numeric()->prefix('Rp')->default(0)->minValue(0)->maxValue(100000000000)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0)->disabledOn('edit')
                ->helperText('Setelah dibuat, harga & vendor terakhir diperbarui otomatis dari tiap pembelian (aksi Stok Masuk).'),
            \App\Filament\Support\QuickAdd::vendor(Select::make('vendor_id')->label('Vendor (pembelian awal)')->options(fn () => Vendor::pluck('name', 'id'))->disabledOn('edit')),
            DatePicker::make('warranty_until')->label('Garansi sampai'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->headerActions(\App\Filament\Support\StockToolbar::actions(\App\Models\Component::class))->columns([
            TextColumn::make('name')->label('Nama')->searchable()->sortable(),
            TextColumn::make('specs')->label('Spesifikasi')->limit(40)->toggleable(),
            TextColumn::make('qty_total')->label('Total'),
            TextColumn::make('qty_available')->label('Tersedia')->badge()->color(fn ($record) => $record->isLow() ? 'danger' : 'success'),
            TextColumn::make('min_qty')->label('Min'),
            TextColumn::make('location.name')->label('Lokasi'),
            TextColumn::make('vendor.name')->label('Vendor terakhir')->toggleable(),
            TextColumn::make('price')->label('Harga terakhir')->money('IDR', locale: 'id')->toggleable(),
            TextColumn::make('average_price')->label('Harga rata-rata')->money('IDR', locale: 'id')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('stock_value')->label('Nilai stok')->money('IDR', locale: 'id')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Filter::make('low')->label('Stok ≤ minimum')->query(fn ($query) => $query->whereColumn('qty_available', '<=', 'min_qty')),
            TrashedFilter::make(),
        ])->recordActions([
            Action::make('label')->label('Label')->icon(Heroicon::OutlinedPrinter)
                ->url(fn ($record) => route('labels.print', ['kind' => 'component', 'ids' => $record->id]), shouldOpenInNewTab: true),
            \App\Filament\Support\StockInAction::make(true),
            \App\Filament\Support\StockInAction::history(),
            \App\Filament\Support\StockHeaderActions::rowStockOut(\App\Models\Component::class),
            \App\Filament\Support\ComponentInstallAction::row(),
            EditAction::make(), DeleteAction::make(), RestoreAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageComponents::route('/')];
    }
}
