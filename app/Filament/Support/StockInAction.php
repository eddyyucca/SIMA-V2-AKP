<?php

namespace App\Filament\Support;

use App\Models\Vendor;
use App\Services\StockService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/** Aksi "Stok Masuk (Pembelian)" untuk Accessories, Consumable, dan Komponen. */
class StockInAction
{
    public static function make(bool $integer = true): Action
    {
        return Action::make('stock_in')->label('Stok Masuk')->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn () => auth()->user()->can('manage inventory'))
            ->modalHeading(fn ($record) => 'Stok masuk: ' . $record->name)
            ->modalDescription('Setiap pembelian dicatat sebagai riwayat tersendiri (vendor, harga, tanggal), sehingga harga berbeda dari vendor berbeda tidak saling menimpa.')
            ->fillForm(fn ($record) => ['vendor_id' => $record->vendor_id, 'unit_price' => $record->{$record->priceColumn()}, 'received_at' => now()->toDateString()])
            ->schema([
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
            ->action(fn (array $data, $record, Action $action) => Ui::run(
                fn () => app(StockService::class)->move($record, 'in', $integer ? (int) $data['qty'] : (float) $data['qty'], [
                    'vendor_id' => $data['vendor_id'], 'unit_price' => (float) $data['unit_price'], 'received_at' => $data['received_at'],
                    'reference_no' => $data['reference_no'] ?? null, 'notes' => $data['notes'] ?? null, 'reason' => 'Pembelian',
                ]),
                'Pembelian dicatat: stok, harga terakhir, dan riwayat sudah diperbarui', $action));
    }

    /** Aksi "Riwayat Pembelian" (modal) per barang. */
    public static function history(): Action
    {
        return Action::make('receipts')->label('Riwayat Pembelian')->icon(Heroicon::OutlinedReceiptPercent)
            ->modalHeading(fn ($record) => 'Riwayat pembelian: ' . $record->name)
            ->modalContent(fn ($record) => view('filament.receipts', ['item' => $record]))
            ->modalSubmitAction(false)->modalCancelActionLabel('Tutup');
    }
}
