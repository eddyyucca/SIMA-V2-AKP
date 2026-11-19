<?php

namespace App\Filament\Resources\Assets;

use App\Filament\Forms\ScanInput;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Support\WithTrashed;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Vendor;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class AssetResource extends Resource
{
    use WithTrashed;

    protected static ?string $model = Asset::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;
    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'Aset';
    protected static ?string $pluralModelLabel = 'Aset';
    protected static ?string $recordTitleAttribute = 'asset_tag';

    public static function getGloballySearchableAttributes(): array
    {
        return ['asset_tag', 'serial_number', 'model', 'brand'];
    }

    /** Aset sewa: halaman Tambah Aset Sewa, atau aset yang sudah berstatus sewa (halaman ubah). */
    public static function isRental(Get $get, $livewire): bool
    {
        return $livewire instanceof \App\Filament\Resources\RentalAssets\Pages\CreateRentalAsset || $get('ownership') === 'rental';
    }

    /**
     * Satu isian spesifikasi: teks biasa, atau pilihan (dropdown bisa dicari) dengan "isi manual / tambah pilihan baru".
     * Nilai manual langsung dipakai; bagi yang berhak mengelola master bisa sekalian disimpan ke daftar pilihan.
     * @param array{0:string,1:string,2:string,3:array} $f
     */
    public static function specComponent(string $k, array $f)
    {
        [$label, $hint, $type, $presets] = $f;
        if ($type !== 'select') {
            return TextInput::make("spec_data.$k")->label($label)->placeholder($hint ?: null)->maxLength(255);
        }
        return Select::make("spec_data.$k")->label($label)->placeholder('Pilih atau cari…')->searchable()->native(false)
            // nilai tersimpan / nilai manual yang belum ada di daftar tetap ikut sebagai pilihan (agar lolos validasi dan tampil)
            ->options(function ($state) use ($presets) {
                $o = $presets;
                if (is_string($state) && trim($state) !== '' && ! in_array($state, $o, true)) $o[] = $state;
                return array_combine($o, $o);
            })
            ->helperText($hint ?: null)
            ->createOptionForm([
                TextInput::make('value')->label($label . ' (isi manual)')->required()->maxLength(100),
                Toggle::make('remember')->label('Simpan ke daftar pilihan')->helperText('Nilai ini akan tersedia di dropdown untuk semua aset berikutnya.')
                    ->default(true)->visible(fn () => auth()->user()?->can('manage master') ?? false),
            ])
            ->createOptionAction(fn ($action) => $action->modalHeading('Isi manual ' . $label)->modalSubmitActionLabel('Pakai nilai ini')->tooltip('Isi manual / tambah pilihan baru'))
            ->createOptionUsing(function (array $data) use ($k) {
                $v = trim((string) $data['value']);
                if (! empty($data['remember']) && (auth()->user()?->can('manage master') ?? false)) Asset::addSpecOption($k, $v);
                return $v;
            });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identitas')->columns(2)->schema([
                Select::make('category_id')->label('Kategori')->required()->searchable()
                    ->options(fn () => Category::where('type', 'asset')->pluck('name', 'id'))
                    ->live()
                    ->afterStateUpdated(function ($state, $old, Set $set, Get $get, string $operation) {
                        if ($operation === 'create' && $state && ($c = Category::find($state))) {
                            $set('asset_tag', Asset::generateTag($c));
                        }
                        // umur ekonomis & residu terisi otomatis dari kategori (Pengaturan → Umur ekonomis); tetap bisa diubah per aset
                        $new = $state ? Category::find($state) : null;
                        $prev = $old ? Category::find($old) : null;
                        $years = $get('useful_life_years'); $res = $get('residual_percent');
                        if ($new && ($years === null || $years === '' || ($prev && (int) $years === $prev->effectiveLifeYears()))) $set('useful_life_years', $new->effectiveLifeYears());
                        if ($new && ($res === null || $res === '' || ($prev && (float) $res === (float) $prev->residual_percent))) $set('residual_percent', (float) $new->residual_percent);
                    }),
                TextInput::make('asset_tag')->label('Kode aset')->required()->unique(ignoreRecord: true)->helperText('Terisi otomatis setelah memilih kategori.'),
                Select::make('brand_id')->label('Merek')->searchable()->preload()->live()
                    ->options(fn () => Brand::orderBy('name')->pluck('name', 'id'))
                    ->createOptionForm([TextInput::make('name')->label('Nama merek')->required()->unique('brands', 'name')])
                    ->createOptionUsing(fn (array $data) => Brand::create($data)->id)
                    ->afterStateUpdated(fn (Set $set) => $set('asset_model_id', null))
                    ->helperText('Dari master Merek. Merek baru bisa ditambah langsung (tombol +).'),
                Select::make('asset_model_id')->label('Model')->searchable()->preload()->live()
                    ->options(fn (Get $get) => $get('brand_id') ? AssetModel::where('brand_id', $get('brand_id'))->orderBy('name')->pluck('name', 'id') : [])
                    ->disabled(fn (Get $get) => ! $get('brand_id'))
                    ->createOptionForm([TextInput::make('name')->label('Nama model')->required(), Textarea::make('default_specs')->label('Spesifikasi bawaan')])
                    ->createOptionUsing(fn (array $data, Get $get) => AssetModel::create($data + ['brand_id' => $get('brand_id'), 'category_id' => $get('category_id')])->id)
                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                        if ($state && blank($get('specs')) && ($m = AssetModel::find($state)) && $m->default_specs) $set('specs', $m->default_specs);
                    })
                    ->helperText('Pilih merek dulu; daftar model mengikuti merek.'),
                ScanInput::make('serial_number')->label('Serial number')->rules(['nullable', 'string', 'max:255'])->rule(fn (?\Illuminate\Database\Eloquent\Model $record) => new \App\Rules\UniqueSerialNumber($record))
                    ->helperText('Scan dengan kamera atau ketik manual. Tidak boleh sama dengan aset atau komponen lain.'),
                \App\Filament\Support\QuickAdd::location(Select::make('location_id')->label('Lokasi')->options(fn () => Location::pluck('name', 'id'))),
                Select::make('condition')->label('Kondisi')->options(Asset::CONDITIONS)->default('good')->required(),
                Select::make('ownership')->label('Kepemilikan')->options(Asset::OWNERSHIP)->default('owned')->required()->live()->visible(fn (string $operation) => $operation === 'edit')
                    ->helperText('Pilih "Sewa (Rental)" untuk aset IT yang disewa dari vendor.'),
                Select::make('status')->label('Status')->options(Asset::STATUSES)->default('available')->disabled()->dehydrated(fn (string $operation) => $operation === 'create')
                    ->helperText('Status berubah otomatis lewat serah terima, perbaikan, dan disposal.'),
                Textarea::make('specs')->columnSpanFull()
                    ->label(fn (Get $get) => Asset::isComputerCategory($get('category_id')) ? 'Catatan spesifikasi tambahan' : 'Spesifikasi')
                    ->helperText(fn (Get $get) => Asset::isComputerCategory($get('category_id')) ? 'Catatan bebas. Riwayat pasang/lepas komponen juga tercatat di sini otomatis.' : null),
            ]),
            Section::make('Spesifikasi Komputer')->columns(1)->visible(fn (Get $get) => Asset::isComputerCategory($get('category_id')))
                ->description('Isi sesuai format pencatatan laptop / PC desktop. Semua kolom boleh dikosongkan dulu.')
                ->schema(collect(Asset::specFields())->map(fn ($f, $k) => static::specComponent((string) $k, $f))->values()->all()),
            Section::make('Sewa (Rental)')->columns(2)->visible(fn (Get $get, $livewire) => static::isRental($get, $livewire))->schema([
                \App\Filament\Support\QuickAdd::vendor(Select::make('vendor_id')->label('Vendor / penyedia sewa')->options(fn () => Vendor::pluck('name', 'id'))->required()),
                TextInput::make('rental_contract_no')->label('No. kontrak sewa'),
                DatePicker::make('rental_start')->label('Mulai sewa')->required(),
                DatePicker::make('rental_end')->label('Sewa berakhir')->required()->afterOrEqual('rental_start'),
                TextInput::make('rental_monthly_cost')->label('Biaya sewa per bulan')->numeric()->maxValue(100000000000)->prefix('Rp')->minValue(0)->default(0)->required(),
                TextInput::make('rental_deposit')->label('Deposit')->numeric()->maxValue(100000000000)->prefix('Rp')->minValue(0)->default(0)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0),
            ]),
            Section::make('Pembelian & Garansi')->columns(2)->visible(fn (Get $get, $livewire) => ! static::isRental($get, $livewire))->schema([
                DatePicker::make('purchase_date')->label('Tanggal beli'),
                \App\Filament\Support\QuickAdd::vendor(Select::make('vendor_id')->label('Vendor')->options(fn () => Vendor::pluck('name', 'id'))),
                TextInput::make('po_pr_no')->label('No. PO / PR')->maxLength(100),
                TextInput::make('purchase_price')->label('Harga beli')->numeric()->minValue(0)->maxValue(100000000000)->prefix('Rp')->default(0)->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0),
                DatePicker::make('warranty_until')->label('Garansi sampai'),
                TextInput::make('useful_life_years')->label('Umur ekonomis (tahun)')->numeric()->integer()->minValue(1)->maxValue(50)
                    ->afterStateHydrated(function (TextInput $component, $state, Get $get) {
                        if (($state === null || $state === '') && ($c = Category::find($get('category_id')))) $component->state($c->effectiveLifeYears());
                    })
                    ->helperText('Terisi otomatis dari kategori (Pengaturan → Umur ekonomis); boleh diubah untuk aset ini. Dipakai menghitung penyusutan garis lurus.'),
                TextInput::make('residual_percent')->label('Nilai residu (%)')->numeric()->minValue(0)->maxValue(100)->suffix('%')
                    ->afterStateHydrated(function (TextInput $component, $state, Get $get) {
                        if (($state === null || $state === '') && ($c = Category::find($get('category_id')))) $component->state((float) $c->residual_percent);
                    })
                    ->helperText('Terisi otomatis dari kategori; boleh diubah untuk aset ini.'),
            ]),
            Section::make('Foto & Dokumen')->columns(2)->schema([
                FileUpload::make('photo_path')->label('Foto aset')->image()->maxSize(5120)->disk('public')->visibility('public')->directory('assets'),
                FileUpload::make('documents')->label('Dokumen (invoice, garansi)')->multiple()->maxSize(5120)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->disk('public')->visibility('public')->directory('asset-docs')->downloadable()->openable(),
                Textarea::make('notes')->label('Catatan')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->where('ownership', 'owned')->with(['category', 'employee', 'location', 'reservationItem.plan']))
            ->columns([
                ImageColumn::make('photo_path')->label('')->disk('public')->circular()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('asset_tag')->label('Kode')->searchable()->sortable()->weight('bold'),
                TextColumn::make('category.name')->label('Kategori'),
                TextColumn::make('brand')->label('Merek / Model')->formatStateUsing(fn ($state, $record) => trim("{$record->brand} {$record->model}"))->searchable(['brand', 'model']),
                TextColumn::make('serial_number')->label('Serial')->searchable()->toggleable(),
                TextColumn::make('employee.name')->label('Pemegang')->searchable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => Asset::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'available' => 'success', 'deployed' => 'info', 'repair' => 'warning', 'borrowed', 'returned' => 'gray', default => 'danger' }),
                TextColumn::make('condition')->label('Kondisi')->badge()->formatStateUsing(fn ($state) => Asset::CONDITIONS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'good' => 'success', 'minor_damage' => 'warning', default => 'danger' })->toggleable(),
                TextColumn::make('location.name')->label('Lokasi')->toggleable(),
                TextColumn::make('warranty_until')->label('Garansi')->date('d/m/Y')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reserved')->label('Dicadangkan untuk')->badge()->color('warning')->placeholder('-')->toggleable()
                    ->state(fn ($record) => $record->reservationItem?->plan?->name),
                TextColumn::make('purchase_price')->label('Harga perolehan')->money('IDR', locale: 'id')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('book_value')->label('Nilai buku')->money('IDR', locale: 'id')->toggleable()
                    ->state(fn ($record) => app(\App\Services\DepreciationService::class)->bookValue($record, now())),
            ])->defaultSort('asset_tag', 'desc')
            ->filters([
                SelectFilter::make('category_id')->label('Kategori')->relationship('category', 'name'),
                SelectFilter::make('status')->options(Asset::STATUSES),
                SelectFilter::make('condition')->label('Kondisi')->options(Asset::CONDITIONS),
                SelectFilter::make('brand_id')->label('Merek')->options(fn () => Brand::orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('location_id')->label('Lokasi')->relationship('location', 'name'),
                SelectFilter::make('department')->label('Department')
                    ->options(fn () => \App\Models\Department::options())
                    ->query(fn ($query, $data) => $data['value'] ? $query->whereHas('employee', fn ($e) => $e->where('department_id', $data['value'])) : $query),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->headerActions(\App\Filament\Support\AssetToolbar::actions())
            ->toolbarActions([
                BulkAction::make('labels')->label('Label A4 (lembar stiker)')->icon(Heroicon::OutlinedPrinter)
                    ->action(function (Collection $records) {
                        $pdf = Pdf::loadView('pdf.labels', ['assets' => $records])->setPaper('a4');
                        return response()->streamDownload(fn () => print($pdf->output()), 'label-aset.pdf');
                    })->deselectRecordsAfterCompletion(),
                BulkAction::make('labels_tape')->label('Label Brother (pita)')->icon(Heroicon::OutlinedPrinter)
                    ->action(function (Collection $records) {
                        $pdf = app(\App\Services\LabelService::class)->pdf('asset', $records, (int) config('labels.tape'), (int) config('labels.length'));
                        return response()->streamDownload(fn () => print($pdf->output()), 'label-aset-pita.pdf');
                    })->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssets::route('/'),
            'create' => CreateAsset::route('/create'),
            'view' => ViewAsset::route('/{record}'),
            'edit' => EditAsset::route('/{record}/edit'),
        ];
    }
}
