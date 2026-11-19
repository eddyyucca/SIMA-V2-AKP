<?php

namespace App\Filament\Resources\Accessories;

use App\Filament\Resources\Accessories\Pages\ManageAccessories;
use App\Filament\Support\Ui;
use App\Filament\Support\WithTrashed;
use App\Models\Accessory;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Vendor;
use App\Services\StockService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use UnitEnum;

class AccessoryResource extends Resource
{
    use WithTrashed;

    protected static ?string $model = Accessory::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;
    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = 'Accessory';
    protected static ?string $pluralModelLabel = 'Accessories';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama')->required(),
            \App\Filament\Support\QuickAdd::category(Select::make('category_id')->label('Kategori')->options(fn () => Category::where('type', 'accessory')->pluck('name', 'id')), 'accessory'),
            TextInput::make('brand_model')->label('Merek / Model'),
            TextInput::make('qty_total')->label('Total')->numeric()->integer()->default(0)->minValue(0)->maxValue(1000000)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0)
                ->helperText('Stok sebaiknya diubah lewat aksi Stok Masuk. Isi hanya untuk stok awal.')->disabledOn('edit')->live(),
            TextInput::make('min_qty')->label('Stok minimum')->numeric()->integer()->default(0)->minValue(0)->maxValue(1000000)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0),
            \App\Filament\Support\QuickAdd::location(Select::make('location_id')->label('Lokasi')->options(fn () => Location::pluck('name', 'id'))),
            TextInput::make('unit_price')->label('Harga satuan (pembelian awal)')->numeric()->prefix('Rp')->default(0)->minValue(0)->maxValue(100000000000)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0)->disabledOn('edit')
                ->helperText('Setelah dibuat, harga & vendor terakhir diperbarui otomatis dari tiap pembelian (aksi Stok Masuk).'),
            \App\Filament\Support\QuickAdd::vendor(Select::make('vendor_id')->label('Vendor (pembelian awal)')->options(fn () => Vendor::pluck('name', 'id'))->disabledOn('edit')),
            FileUpload::make('photo_path')->label('Foto')->image()->maxSize(5120)->disk('public')->visibility('public')->directory('accessories'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->headerActions(\App\Filament\Support\StockToolbar::actions(\App\Models\Accessory::class))->columns([
            TextColumn::make('name')->label('Nama')->searchable()->sortable(),
            TextColumn::make('category.name')->label('Kategori'),
            TextColumn::make('qty_total')->label('Total')->sortable(),
            TextColumn::make('qty_available')->label('Tersedia')->sortable()->badge()
                ->color(fn ($record) => $record->isLow() ? 'danger' : 'success'),
            TextColumn::make('min_qty')->label('Min'),
            TextColumn::make('location.name')->label('Lokasi'),
            TextColumn::make('vendor.name')->label('Vendor terakhir')->toggleable(),
            TextColumn::make('unit_price')->label('Harga terakhir')->money('IDR', locale: 'id')->toggleable(),
            TextColumn::make('average_price')->label('Harga rata-rata')->money('IDR', locale: 'id')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('stock_value')->label('Nilai stok')->money('IDR', locale: 'id')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Filter::make('low')->label('Stok ≤ minimum')->query(fn ($query) => $query->whereColumn('qty_available', '<=', 'min_qty')),
            TrashedFilter::make(),
        ])->recordActions([
            Action::make('label')->label('Label')->icon(Heroicon::OutlinedPrinter)
                ->url(fn ($record) => route('labels.print', ['kind' => 'accessory', 'ids' => $record->id]), shouldOpenInNewTab: true),
            \App\Filament\Support\StockInAction::make(true),
            \App\Filament\Support\StockInAction::history(),
            \App\Filament\Support\StockHeaderActions::rowStockOut(\App\Models\Accessory::class, 'checkout', 'Serahkan / Keluarkan'),
            Action::make('checkin')->label('Terima Kembali')->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn () => auth()->user()->can('manage inventory'))
                ->schema([
                    Select::make('employee_id')->label('Dari karyawan')->required()->searchable()
                        ->options(fn (Accessory $record) => Employee::whereIn('id', $record->movements()->whereIn('type', ['out', 'return'])->whereNotNull('employee_id')->pluck('employee_id'))->pluck('name', 'id')),
                    TextInput::make('qty')->label('Jumlah')->numeric()->maxValue(1000000)->minValue(1)->default(1)->required(),
                    Select::make('condition')->label('Kondisi saat kembali')->options(\App\Models\Asset::CONDITIONS)->default('good')->required(),
                ])
                ->action(function (array $data, Accessory $record, Action $action) {
                    static::handover($action, fn () => app(\App\Services\AccessoryHandoverService::class)->receiveBack(
                        Employee::findOrFail($data['employee_id']), [['accessory_id' => $record->id, 'qty' => $data['qty'], 'condition' => $data['condition']]]), 'Accessory diterima kembali');
                }),
            Action::make('holders')->label('Pemegang')->icon(Heroicon::OutlinedUsers)
                ->modalHeading(fn ($record) => 'Pemegang: ' . $record->name)
                ->modalContent(fn ($record) => view('filament.holders', ['rows' => $record->movements()->whereIn('type', ['out', 'return'])->whereNotNull('employee_id')->with('employee')->get()
                    ->groupBy('employee_id')->map(fn ($g) => ['name' => $g->first()->employee?->name, 'detail' => $g->sum(fn ($m) => $m->type === 'out' ? $m->qty : -$m->qty) . ' unit'])
                    ->filter(fn ($r) => (int) $r['detail'] > 0)->values()]))
                ->modalSubmitAction(false)->modalCancelActionLabel('Tutup'),
            EditAction::make(), DeleteAction::make(), RestoreAction::make(),
        ]);
    }

    /** Jalankan serah terima / pengembalian; sukses menampilkan nomor BAST + tombol cetak, gagal menampilkan alasannya dan membatalkan aksi. */
    private static function handover(Action $action, \Closure $run, string $title): void
    {
        try {
            $h = $run();
        } catch (\InvalidArgumentException $e) {
            \Filament\Notifications\Notification::make()->title('Gagal memproses serah terima')->body($e->getMessage())->danger()->send();
            $action->halt();
            return;
        }
        \Filament\Notifications\Notification::make()->success()->title($title . ' — BAST ' . $h->bast_number)->body('Cetak, tanda tangani kedua pihak, lalu unggah scan-nya.')
            ->actions([\Filament\Actions\Action::make('print')->label('Cetak BAST')->url(route('accessory-bast.view', $h), shouldOpenInNewTab: true)->button()])->send();
    }

    public static function getPages(): array
    {
        return ['index' => ManageAccessories::route('/')];
    }
}
