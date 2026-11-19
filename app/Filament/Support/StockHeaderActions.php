<?php

namespace App\Filament\Support;

use App\Filament\Resources\AccessoryHandovers\AccessoryHandoverResource;
use App\Filament\Pages\StockHistory\AccessoryStockHistory;
use App\Filament\Pages\StockHistory\ComponentStockHistory;
use App\Filament\Pages\StockHistory\ConsumableStockHistory;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Vendor;
use App\Services\AccessoryHandoverService;
use App\Services\StockService;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Tombol di samping "Buat ..." pada halaman Accessories, Consumable, dan Komponen: Riwayat Stok, Stok Masuk, Stok Keluar.
 * Semuanya bermuara ke StockService / BAST yang sama dengan aksi per baris, jadi data antar halaman selalu nyambung.
 */
class StockHeaderActions
{
    /** @param class-string $class model barang (Accessory | Consumable | Component) */
    public static function make(string $class): array
    {
        return [static::history($class), static::stockIn($class), static::stockOut($class)];
    }

    private static function canManage(): bool { return auth()->user()?->can('manage inventory') ?? false; }

    private static function items(string $class): array
    {
        return $class::orderBy('name')->get()->mapWithKeys(function ($i) use ($class) {
            $left = $class === Consumable::class ? (float) $i->qty . ' ' . $i->unit : $i->qty_available;
            return [$i->id => $i->code . ' — ' . $i->name . ' (stok ' . $left . ')'];
        })->all();
    }

    /** Riwayat Stok milik jenis barang itu sendiri (tidak ada halaman gabungan). */
    public static function historyUrl(string $class): string
    {
        return [Accessory::class => AccessoryStockHistory::class, Consumable::class => ConsumableStockHistory::class, Component::class => ComponentStockHistory::class][$class]::getUrl();
    }

    public static function history(string $class): Action
    {
        return Action::make('stock_history')->label('Riwayat Stok')->icon(Heroicon::OutlinedClock)->color('gray')
            ->url(static::historyUrl($class));
    }

    public static function stockIn(string $class): Action
    {
        $integer = $class !== Consumable::class;
        return Action::make('stock_in_header')->label('Stok Masuk')->icon(Heroicon::OutlinedPlusCircle)->color('gray')
            ->visible(fn () => static::canManage())
            ->modalHeading('Stok masuk (pembelian)')
            ->modalDescription('Pilih barang yang dibeli. Tercatat sebagai riwayat pembelian (vendor, harga, tanggal) dan langsung menambah stok.')
            ->fillForm(['received_at' => now()->toDateString()])
            ->schema([
                Select::make('item_id')->label('Barang')->required()->searchable()->live()->options(fn () => static::items($class))
                    ->afterStateUpdated(function ($state, $set) use ($class) {
                        $i = $state ? $class::find($state) : null;
                        if ($i) { $set('vendor_id', $i->vendor_id); $set('unit_price', $i->{$i->priceColumn()}); }
                    }),
                Select::make('vendor_id')->label('Vendor / pemasok')->required()->searchable()
                    ->options(fn () => Vendor::orderBy('name')->pluck('name', 'id'))
                    ->createOptionForm([TextInput::make('name')->label('Nama vendor')->required()])
                    ->createOptionUsing(fn (array $data) => Vendor::create($data)->id),
                TextInput::make('qty')->label('Jumlah')->numeric()->maxValue(1000000)->required()->minValue($integer ? 1 : 0.01)->rules($integer ? ['integer'] : []),
                TextInput::make('unit_price')->label('Harga satuan')->numeric()->maxValue(100000000000)->prefix('Rp')->required()->minValue(0),
                DatePicker::make('received_at')->label('Tanggal terima')->required()->maxDate(now()),
                TextInput::make('reference_no')->label('No. PO / Invoice')->maxLength(100),
                Textarea::make('notes')->label('Catatan'),
            ])
            ->action(fn (array $data, Action $action) => Ui::run(
                fn () => app(StockService::class)->move($class::findOrFail($data['item_id']), 'in', $integer ? (int) $data['qty'] : (float) $data['qty'], [
                    'vendor_id' => $data['vendor_id'], 'unit_price' => (float) $data['unit_price'], 'received_at' => $data['received_at'],
                    'reference_no' => $data['reference_no'] ?? null, 'notes' => $data['notes'] ?? null, 'reason' => 'Pembelian',
                ]),
                'Pembelian dicatat: stok, harga terakhir, dan riwayat sudah diperbarui', $action));
    }

    public const KIND = ['App\\Models\\Accessory' => 'accessory', 'App\\Models\\Consumable' => 'consumable', 'App\\Models\\Component' => 'component'];

    /** Tombol "Stok Keluar" di header: pilih barang + tujuan; hasilnya dokumen BAST yang bisa langsung dicetak. */
    public static function stockOut(string $class): Action
    {
        $kind = self::KIND[$class];
        return Action::make('stock_out_header')->label('Stok Keluar')->icon(Heroicon::OutlinedMinusCircle)->color('gray')
            ->visible(fn () => static::canManage())
            ->modalHeading('Stok keluar')
            ->modalDescription($kind === 'component'
                ? 'Stok keluar = komponen diserahkan ke karyawan / department / lokasi (dokumen BAST). Untuk MEMASANG komponen ke laptop atau PC (upgrade / perbaikan) gunakan tombol "Pasang ke Aset".'
                : 'Setiap stok keluar membuat dokumen BAST (cetak, tanda tangan, lalu unggah scan-nya).')
            ->schema([
                Select::make('item_id')->label('Barang')->required()->searchable()->options(fn () => static::items($class)),
                ...static::outFields($kind),
            ])
            ->action(function (array $data, Action $action) use ($kind) {
                static::runOut($action, $kind, (int) $data['item_id'], $data);
            });
    }

    /** Isian tujuan stok keluar (dipakai tombol header dan aksi per baris). Perangkat untuk accessories dan consumable (bukan komponen). */
    public static function outFields(string $kind): array
    {
        $targets = ['employee' => 'Karyawan', 'department' => 'Department', 'location' => 'Lokasi'] + ($kind !== 'component' ? ['asset' => 'Perangkat'] : []);
        return [
            TextInput::make('qty')->label('Jumlah')->numeric()->maxValue(1000000)->required()->default(1)
                ->minValue($kind === 'consumable' ? 0.01 : 1)->rules($kind === 'consumable' ? [] : ['integer']),
            Select::make('target')->label('Tujuan')->options($targets)->default('employee')->live()->required()->native(false),
            Select::make('employee_id')->searchable()->native(false)->options(fn () => Employee::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                ->label(fn (Get $get) => ($get('target') ?? 'employee') === 'employee' ? 'Karyawan penerima' : 'PIC penerima (opsional)')
                ->required(fn (Get $get) => ($get('target') ?? 'employee') === 'employee'),
            Select::make('department_id')->label('Department')->searchable()->native(false)->options(fn () => Department::orderBy('name')->pluck('name', 'id'))
                ->visible(fn (Get $get) => $get('target') === 'department')->required(fn (Get $get) => $get('target') === 'department'),
            Select::make('location_id')->label('Lokasi')->searchable()->native(false)->options(fn () => \App\Models\Location::orderBy('name')->pluck('name', 'id'))
                ->visible(fn (Get $get) => $get('target') === 'location')->required(fn (Get $get) => $get('target') === 'location'),
            Select::make('asset_id')->label('Perangkat')->searchable()->native(false)
                ->options(fn () => Asset::orderBy('asset_tag')->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->asset_tag} — {$a->brand} {$a->model}"])->all())
                ->visible(fn (Get $get) => $get('target') === 'asset')->required(fn (Get $get) => $get('target') === 'asset'),
            Textarea::make('notes')->label('Catatan (opsional)'),
        ];
    }

    /** Jalankan stok keluar lewat BAST: sukses -> nomor BAST + tombol cetak; gagal -> alasan, aksi dibatalkan. */
    public static function runOut(Action $action, string $kind, int $itemId, array $data, ?string $title = null): void
    {
        $target = $data['target'] ?? 'employee';
        try {
            $employee = ! empty($data['employee_id']) ? Employee::find($data['employee_id']) : null;
            $h = app(AccessoryHandoverService::class)->checkout($employee, [['item' => "$kind:$itemId", 'qty' => $data['qty']]], null, $data['notes'] ?? null, [
                'type' => $target, 'department_id' => $data['department_id'] ?? null, 'location_id' => $data['location_id'] ?? null, 'asset_id' => $data['asset_id'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title(($title ?? 'Stok keluar') . ' gagal')->body($e->getMessage())->danger()->send();
            $action->halt();
            return;
        }
        Notification::make()->success()->title(($title ?? 'Stok keluar dicatat') . ' — BAST ' . $h->bast_number)->body('Cetak, tanda tangani, lalu unggah scan-nya.')
            ->actions([\Filament\Actions\Action::make('print')->label('Cetak BAST')->url(route('accessory-bast.view', $h), shouldOpenInNewTab: true)->button()])->send();
    }

    /** Aksi "Keluarkan" per baris barang (consumable / komponen / accessories). */
    public static function rowStockOut(string $class, string $name = 'stock_out', string $label = 'Keluarkan'): Action
    {
        $kind = self::KIND[$class];
        return Action::make($name)->label($label)->icon(Heroicon::OutlinedMinusCircle)
            ->visible(fn () => static::canManage())
            ->modalHeading(fn ($record) => 'Stok keluar: ' . $record->name)
            ->modalDescription($kind === 'component'
                ? 'Stok keluar = komponen diserahkan ke karyawan / department / lokasi (dokumen BAST). Untuk MEMASANG ke laptop atau PC gunakan "Pasang ke Aset".'
                : 'Setiap stok keluar membuat dokumen BAST (cetak, tanda tangan, lalu unggah scan-nya).')
            ->schema(static::outFields($kind))
            ->action(fn (array $data, $record, Action $action) => static::runOut($action, $kind, (int) $record->getKey(), $data));
    }
}
