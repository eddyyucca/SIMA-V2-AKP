<?php

namespace App\Filament\Support;

use App\Models\Accessory;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\StockMovement;
use App\Services\StockDocumentService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabel Riwayat Stok untuk SATU jenis barang (Accessories, Consumable, atau Komponen). Tiap menu punya riwayatnya sendiri;
 * tidak ada halaman gabungan. Kolom, tipe pergerakan, dan filter menyesuaikan jenisnya.
 */
class StockHistoryTable
{
    public const TYPES = ['in' => 'Masuk', 'out' => 'Keluar', 'return' => 'Kembali', 'install' => 'Dipasang', 'uninstall' => 'Dilepas', 'adjust' => 'Koreksi'];

    /** Tipe pergerakan yang relevan per jenis barang. */
    public const KIND_TYPES = [
        Accessory::class => ['in', 'out', 'return', 'adjust'],
        Consumable::class => ['in', 'out', 'return', 'adjust'],
        Component::class => ['in', 'out', 'install', 'uninstall', 'adjust'],
    ];

    public static function types(string $class): array
    {
        return array_intersect_key(self::TYPES, array_flip(self::KIND_TYPES[$class]));
    }

    /** Tab: kunci => [label, ikon, tipe pergerakan (null = semua)]. */
    public static function tabs(string $class): array
    {
        $tabs = ['all' => ['Semua', null, null], 'in' => ['Stok Masuk', 'heroicon-o-plus-circle', ['in']], 'out' => ['Stok Keluar', 'heroicon-o-minus-circle', ['out']]];
        if ($class === Component::class) $tabs['install'] = ['Dipasang / Dilepas', 'heroicon-o-wrench-screwdriver', ['install', 'uninstall']];
        $tabs['other'] = ['Lainnya', null, $class === Component::class ? ['return', 'adjust'] : ['return', 'adjust']];
        return $tabs;
    }

    private static function documentCount(StockMovement $r): int
    {
        return $r->files_count + ($r->handoverItem?->handover?->files->count() ?? 0);
    }

    /** @param \Closure():string $activeTab  tab aktif (dibaca setiap tabel dirender) */
    public static function configure(Table $table, string $class, \Closure $activeTab): Table
    {
        $isConsumable = $class === Consumable::class;
        $types = self::types($class);
        $tabs = self::tabs($class);

        return $table
            ->query(StockMovement::query()->where('stockable_type', $class)->with(['stockable', 'employee', 'asset', 'department', 'location', 'receipt.vendor', 'handoverItem.handover.files'])->withCount('files'))
            ->modifyQueryUsing(function (Builder $query) use ($activeTab, $tabs) {
                $t = $tabs[$activeTab()][2] ?? null;
                return $t ? $query->whereIn('type', $t) : $query;
            })
            ->columns(array_values(array_filter([
                TextColumn::make('moved_at')->label('Waktu')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('stockable.name')->label('Barang')->weight('medium')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHasMorph('stockable', [$class], fn ($q) => $q->where('name', 'like', "%{$search}%"))),
                TextColumn::make('type')->label('Tipe')->badge()->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'in' => 'success', 'out' => 'warning', 'return' => 'info', 'install' => 'primary', 'uninstall' => 'gray', default => 'gray' }),
                TextColumn::make('qty')->label('Jumlah')
                    ->formatStateUsing(fn ($state, StockMovement $r) => rtrim(rtrim(number_format((float) $state, 2, ',', '.'), '0'), ',')       // bulat tanpa desimal, desimal bila perlu (1,5)
                        . ($isConsumable && $r->stockable?->unit ? ' ' . $r->stockable->unit : '')),
                TextColumn::make('receipt.vendor.name')->label('Vendor')->placeholder('-')->toggleable(),
                TextColumn::make('receipt.unit_price')->label('Harga satuan')->money('IDR', locale: 'id')->placeholder('-')->toggleable(),
                TextColumn::make('bast')->label('No. BAST')->placeholder('-')->weight('medium')
                    ->state(fn (StockMovement $r) => $r->handoverItem?->handover?->bast_number)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('handoverItem.handover', fn ($q) => $q->where('bast_number', 'like', "%{$search}%"))),
                TextColumn::make('employee.name')->label('Karyawan')->placeholder('-'),
                TextColumn::make('department.name')->label('Department')->placeholder('-'),
                TextColumn::make('location.name')->label('Lokasi')->placeholder('-'),
                $class === Accessory::class ? null : TextColumn::make('asset.asset_tag')->label('Aset')->placeholder('-'),
                TextColumn::make('documents_count')->label('Dokumen')->badge()
                    ->state(fn (StockMovement $r) => self::documentCount($r))
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state . ' file' : 'Belum')->color(fn ($state) => $state > 0 ? 'success' : 'gray'),
            ])))
            ->defaultSort('moved_at', 'desc')
            ->filters([
                SelectFilter::make('type')->label('Tipe')->options($types),
                SelectFilter::make('origin')->label('Asal')->options(['bast' => 'Dari BAST (serah terima / pengembalian / pemasangan)', 'other' => 'Tanpa BAST (pembelian, koreksi, dll.)'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'bast' => $query->has('handoverItem'), 'other' => $query->doesntHave('handoverItem'), default => $query,
                    }),
                SelectFilter::make('stockable_id')->label('Barang')->searchable()
                    ->options(fn () => $class::orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('moved')->label('Tanggal')->schema([
                    DatePicker::make('from')->label('Dari tanggal'), DatePicker::make('until')->label('Sampai tanggal'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('moved_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('moved_at', '<=', $d))),
            ])
            ->recordActions([
                Action::make('bast_print')->label('Cetak BAST')->icon(Heroicon::OutlinedPrinter)
                    ->visible(fn (StockMovement $r) => (bool) $r->handoverItem?->handover)
                    ->url(fn (StockMovement $r) => route('accessory-bast.view', $r->handoverItem->handover), shouldOpenInNewTab: true),
                Action::make('bast_download')->label('Unduh BAST')->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (StockMovement $r) => (bool) $r->handoverItem?->handover)
                    ->url(fn (StockMovement $r) => route('accessory-bast.download', $r->handoverItem->handover)),
                Action::make('upload')->label('Upload')->icon(Heroicon::OutlinedArrowUpOnSquare)
                    ->visible(fn () => auth()->user()->can('manage inventory'))
                    ->modalHeading(fn (StockMovement $r) => 'Upload dokumen — ' . ($r->stockable?->name ?? 'barang') . ' (' . (self::TYPES[$r->type] ?? $r->type) . ' ' . (float) $r->qty . ')')
                    ->modalDescription(fn (StockMovement $r) => $r->handoverItem
                        ? 'Pergerakan ini berasal dari BAST ' . $r->handoverItem->handover?->bast_number . ': dokumen disimpan di arsip BAST itu. Setiap unggahan disimpan permanen.'
                        : 'Mis. faktur, surat jalan, atau dokumen serah terima bertanda tangan. Setiap unggahan disimpan permanen dan tidak menimpa file sebelumnya.')
                    ->modalSubmitActionLabel('Simpan')
                    ->schema([
                        FileUpload::make('files')->label('Dokumen (PDF / JPG / PNG)')->required()->multiple()->maxFiles(5)->disk('local')->directory(StockDocumentService::DIR)->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)->storeFileNamesIn('file_names'),
                        TextInput::make('note')->label('Keterangan (opsional)')->maxLength(250),
                    ])
                    ->action(function (array $data, StockMovement $record, Action $action) {
                        Ui::run(function () use ($data, $record) {
                            $names = (array) ($data['file_names'] ?? []);
                            foreach ((array) $data['files'] as $key => $path) {
                                app(StockDocumentService::class)->attach($record, (string) $path, $names[$path] ?? ($names[$key] ?? null), $data['note'] ?? null);
                            }
                        }, 'Dokumen diunggah dan diarsipkan', $action);
                    }),
                Action::make('documents')->label('Lihat Dokumen')->icon(Heroicon::OutlinedFolderOpen)
                    ->visible(fn (StockMovement $r) => self::documentCount($r) > 0)
                    ->modalHeading(fn (StockMovement $r) => 'Dokumen — ' . ($r->stockable?->name ?? 'barang') . ' (' . (self::TYPES[$r->type] ?? $r->type) . ' ' . (float) $r->qty . ')')
                    ->modalContent(fn (StockMovement $r) => view('filament.stock-movement-docs', ['documents' => $r->documents()]))
                    ->modalWidth('3xl')->modalSubmitAction(false)->modalCancelActionLabel('Tutup'),
            ])
            ->emptyStateHeading('Belum ada riwayat')->emptyStateDescription('Riwayat muncul saat stok masuk, keluar, atau berubah.');
    }
}
