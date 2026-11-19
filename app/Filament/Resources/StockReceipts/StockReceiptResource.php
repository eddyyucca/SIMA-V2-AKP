<?php

namespace App\Filament\Resources\StockReceipts;

use App\Filament\Resources\StockReceipts\Pages\ManageStockReceipts;
use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\StockReceipt;
use App\Models\Vendor;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class StockReceiptResource extends Resource
{
    protected static ?string $model = StockReceipt::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;
    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';
    protected static ?int $navigationSort = 6;
    protected static ?string $modelLabel = 'Pembelian Stok';
    protected static ?string $pluralModelLabel = 'Riwayat Pembelian Stok';

    public const KINDS = [Accessory::class => 'Accessory', Consumable::class => 'Consumable', Component::class => 'Komponen'];

    /** Koreksi data pembelian (vendor, harga, tanggal, no. PO). Jumlah tidak diubah di sini: gunakan koreksi stok. */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            \App\Filament\Support\QuickAdd::vendor(Select::make('vendor_id')->label('Vendor / pemasok')->required()->options(fn () => Vendor::orderBy('name')->pluck('name', 'id'))),
            TextInput::make('unit_price')->label('Harga satuan')->numeric()->maxValue(100000000000)->prefix('Rp')->required()->minValue(0),
            DatePicker::make('received_at')->label('Tanggal terima')->required()->maxDate(now()),
            TextInput::make('reference_no')->label('No. PO / Invoice')->maxLength(100),
            Textarea::make('notes')->label('Catatan'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['stockable', 'vendor', 'creator']))->columns([
            TextColumn::make('received_at')->label('Tanggal')->date('d/m/Y')->sortable(),
            TextColumn::make('stockable_type')->label('Jenis')->formatStateUsing(fn ($state) => self::KINDS[$state] ?? $state)->badge(),
            TextColumn::make('stockable.name')->label('Barang')
                ->searchable(query: fn ($query, string $search) => $query->whereHasMorph('stockable', array_keys(self::KINDS), fn ($q) => $q->where('name', 'like', "%{$search}%"))),
            TextColumn::make('vendor.name')->label('Vendor')->sortable(),
            TextColumn::make('reference_no')->label('No. PO')->searchable(),
            TextColumn::make('qty')->label('Jumlah')->numeric(decimalPlaces: 0),
            TextColumn::make('unit_price')->label('Harga satuan')->money('IDR', locale: 'id')->sortable(),
            TextColumn::make('total')->label('Total')->state(fn ($record) => $record->total)->money('IDR', locale: 'id')
                ->summarize(Summarizer::make()->label('Total belanja')->using(fn ($query) => $query->sum(DB::raw('qty * unit_price')))->money('IDR', locale: 'id')),
            TextColumn::make('creator.name')->label('Dicatat oleh')->toggleable(isToggledHiddenByDefault: true),
        ])->defaultSort('received_at', 'desc')->filters([
            SelectFilter::make('stockable_type')->label('Jenis barang')->options(self::KINDS),
            SelectFilter::make('vendor_id')->label('Vendor')->options(fn () => Vendor::orderBy('name')->pluck('name', 'id')),
            Filter::make('period')->label('Periode')->schema([DatePicker::make('from')->label('Dari tanggal'), DatePicker::make('until')->label('Sampai tanggal')])
                ->query(fn ($query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('received_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('received_at', '<=', $d))),
        ])->recordActions([EditAction::make()->label('Koreksi')]);
    }

    public static function canCreate(): bool { return false; }

    public static function getPages(): array
    {
        return ['index' => ManageStockReceipts::route('/')];
    }
}
