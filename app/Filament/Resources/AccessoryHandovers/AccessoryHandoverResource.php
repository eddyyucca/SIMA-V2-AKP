<?php

namespace App\Filament\Resources\AccessoryHandovers;

use App\Filament\Resources\AccessoryHandovers\Pages\CreateAccessoryHandover;
use App\Filament\Resources\AccessoryHandovers\Pages\ListAccessoryHandovers;
use App\Filament\Support\Ui;
use App\Models\Accessory;
use App\Models\AccessoryHandover;
use App\Models\Asset;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\Department;
use App\Models\Location;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Services\AccessoryHandoverService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Serah terima / pengembalian barang pendukung (accessories, consumable, komponen) antara IT dan karyawan, lengkap dengan BAST (cetak, tanda tangan basah, unggah scan). */
class AccessoryHandoverResource extends Resource
{
    protected static ?string $model = AccessoryHandover::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;
    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Serah Terima Barang Pendukung';
    protected static ?string $modelLabel = 'Serah Terima Barang Pendukung';
    protected static ?string $pluralModelLabel = 'Serah Terima Barang Pendukung';
    protected static ?string $recordTitleAttribute = 'bast_number';

    /** Serah terima kini ada di Riwayat Stok masing-masing menu (Accessories / Consumable / Komponen); halaman ini hanya dibuka lewat tautan (mis. dari request ITR). */
    public static function shouldRegisterNavigation(): bool { return false; }

    /** Request ITR yang sudah disetujui dan belum Completed (opsional dibatasi ke satu karyawan). */
    public static function requestOptions(?int $employeeId = null): array
    {
        $userId = $employeeId ? Employee::whereKey($employeeId)->value('user_id') : null;
        return ITRequest::where('type', 'ITR')->whereIn('status', AccessoryHandoverService::REQUEST_STATUSES)
            ->when($employeeId, fn ($q) => $q->where(fn ($w) => $w->where('user_id', $userId ?: 0)->orWhereNull('user_id')))
            ->orderByDesc('id')->limit(100)->get()
            ->mapWithKeys(fn (ITRequest $r) => [$r->id => ($r->ticket_number ?? '-') . ' — ' . $r->requester_name . ' — ' . \Illuminate\Support\Str::limit($r->title, 50)])->all();
    }

    /** Tujuan yang boleh dipilih: perangkat tidak untuk komponen (komponen dipasang lewat aksi Pasang ke Aset). */
    public static function targetOptions(array $lines): array
    {
        $opts = AccessoryHandover::TARGETS;
        $kinds = collect($lines)->pluck('item')->filter()->map(fn ($i) => explode(':', (string) $i)[0])->unique();
        if ($kinds->contains('component')) unset($opts['asset']);
        return $opts;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')->label('Jenis')->options(AccessoryHandover::FORM_TYPES)->default('checkout')->required()->native(false)->live()
                ->helperText('Serah terima = IT menyerahkan barang. Pengembalian = barang kembali ke IT dari karyawan, department, lokasi, atau perangkat (hanya accessories; consumable dan komponen tercatat sebagai barang keluar).'),
            Select::make('target_type')->label(fn (Get $get) => $get('type') === 'return' ? 'Dikembalikan dari' : 'Tujuan stok keluar')
                ->options(fn (Get $get) => $get('type') === 'return' ? AccessoryHandover::TARGETS : static::targetOptions($get('lines') ?? []))->default('employee')->required()->native(false)->live()
                ->helperText(fn (Get $get) => $get('type') === 'return'
                    ? 'Pilih asal barang. Hanya accessories yang masih berada di sana yang bisa dikembalikan.'
                    : 'Karyawan = tercatat sebagai pemegang barang. Department / Lokasi / Perangkat (kelengkapan laptop, PC, dll.) = tercatat keluar ke tujuan itu dan bisa dikembalikan. Dokumen BAST tetap dibuat.'),
            Select::make('department_id')->label('Department tujuan')->searchable()->native(false)->options(fn () => Department::options())
                ->visible(fn (Get $get) => $get('target_type') === 'department')->required(fn (Get $get) => $get('target_type') === 'department')->live(),
            Select::make('location_id')->label('Lokasi tujuan')->searchable()->native(false)->options(fn () => Location::orderBy('name')->pluck('name', 'id'))
                ->visible(fn (Get $get) => $get('target_type') === 'location')->required(fn (Get $get) => $get('target_type') === 'location')->live(),
            Select::make('asset_id')->label('Perangkat tujuan')->searchable()->native(false)
                ->options(fn () => Asset::orderBy('asset_tag')->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->asset_tag} — {$a->brand} {$a->model}"])->all())
                ->visible(fn (Get $get) => $get('target_type') === 'asset')->required(fn (Get $get) => $get('target_type') === 'asset')->live(),
            Select::make('employee_id')->searchable()->live()
                ->label(fn (Get $get) => ($get('target_type') ?? 'employee') !== 'employee' ? (($get('type') ?? 'checkout') === 'return' ? 'PIC yang menyerahkan (opsional)' : 'PIC penerima (opsional)') : 'Karyawan')
                ->required(fn (Get $get) => ($get('target_type') ?? 'employee') === 'employee')
                ->options(fn (Get $get) => Employee::when($get('type') !== 'return', fn ($q) => $q->where('status', 'active'))->orderBy('name')->pluck('name', 'id')),
            Select::make('request_id')->label('Request ITR (opsional)')->searchable()->native(false)->placeholder('Tidak terkait request')
                ->visible(fn (Get $get) => $get('type') !== 'return')
                ->options(fn (Get $get) => static::requestOptions($get('employee_id') ? (int) $get('employee_id') : null))
                ->helperText('Hanya request ITR yang sudah disetujui. Penerima harus sama dengan karyawan yang mengajukan.'),
            Repeater::make('lines')->label('Barang')->minItems(1)->defaultItems(1)->addActionLabel('Tambah barang')->columnSpanFull()->columns(3)->schema([
                Select::make('item')->label('Barang')->required()->searchable()->native(false)
                    ->options(function (Get $get) {
                        if ($get('../../type') === 'return') {
                            $tt = $get('../../target_type') ?? 'employee';
                            $held = $tt === 'employee'
                                ? ($get('../../employee_id') ? Employee::find($get('../../employee_id'))?->heldAccessories() : null)
                                : ($get('../../' . $tt . '_id') ? app(AccessoryHandoverService::class)->heldAt($tt, (int) $get('../../' . $tt . '_id')) : null);
                            return ['Accessories' => collect($held ?? [])
                                ->filter(fn ($h) => $h['accessory'])->mapWithKeys(fn ($h) => ['accessory:' . $h['accessory']->id => $h['accessory']->name . ' (dipegang ' . $h['qty'] . ')'])->all()];
                        }
                        return array_filter([
                            'Accessories' => Accessory::orderBy('name')->get()->mapWithKeys(fn (Accessory $a) => ['accessory:' . $a->id => $a->name . ' — tersedia ' . $a->qty_available])->all(),
                            'Consumable' => Consumable::orderBy('name')->get()->mapWithKeys(fn (Consumable $c) => ['consumable:' . $c->id => $c->name . ' — tersedia ' . (float) $c->qty . ' ' . $c->unit])->all(),
                            'Komponen' => Component::orderBy('name')->get()->mapWithKeys(fn (Component $c) => ['component:' . $c->id => $c->name . ' — tersedia ' . $c->qty_available])->all(),
                        ]);
                    }),
                TextInput::make('qty')->label('Jumlah')->numeric()->maxValue(1000000)->integer()->minValue(1)->default(1)->required(),
                Select::make('condition')->label('Kondisi saat kembali')->options(Asset::CONDITIONS)->default('good')->native(false)
                    ->visible(fn (Get $get) => $get('../../type') === 'return'),
            ]),
            Textarea::make('notes')->label('Catatan (opsional)')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['employee', 'department', 'location', 'asset', 'pic', 'items.stockable', 'request', 'creator'])->withCount('files'))
            ->columns([
                TextColumn::make('bast_number')->label('No. BAST')->searchable()->sortable()->weight('bold'),
                TextColumn::make('type')->label('Jenis')->badge()->formatStateUsing(fn ($state) => AccessoryHandover::TYPES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'return' ? 'info' : 'success'),
                TextColumn::make('recipient')->label('Penerima / Tujuan')->state(fn (AccessoryHandover $r) => $r->recipientLabel())->wrap()
                    ->searchable(query: fn ($query, string $search) => $query->where(fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('department', fn ($e) => $e->where('name', 'like', "%{$search}%"))->orWhereHas('location', fn ($e) => $e->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('pic', fn ($e) => $e->where('name', 'like', "%{$search}%"))->orWhereHas('asset', fn ($e) => $e->where('asset_tag', 'like', "%{$search}%")))),
                TextColumn::make('items_summary')->label('Barang')->state(fn (AccessoryHandover $r) => $r->itemsSummary())->wrap(),
                TextColumn::make('request.ticket_number')->label('Request')->state(fn (AccessoryHandover $r) => $r->request?->ticket_number)->placeholder('-'),
                TextColumn::make('handed_at')->label('Tanggal')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('creator.name')->label('Dibuat oleh')->toggleable(),
                TextColumn::make('files_count')->label('TTD')->badge()->formatStateUsing(fn ($state) => $state > 0 ? 'Sudah diunggah' : 'Belum')->color(fn ($state) => $state > 0 ? 'success' : 'warning'),
            ])->defaultSort('handed_at', 'desc')
            ->filters([
                SelectFilter::make('type')->label('Jenis')->options(AccessoryHandover::TYPES),
                SelectFilter::make('target_type')->label('Tujuan')->options(AccessoryHandover::TARGETS),
                SelectFilter::make('signed')->label('Scan TTD')->options(['yes' => 'Sudah diunggah', 'no' => 'Belum'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) { 'yes' => $query->has('files'), 'no' => $query->doesntHave('files'), default => $query }),
            ])
            ->recordActions([
                Action::make('bast')->label('Cetak BAST')->icon(Heroicon::OutlinedPrinter)->url(fn (AccessoryHandover $r) => route('accessory-bast.view', $r), shouldOpenInNewTab: true),
                Action::make('download')->label('Unduh')->icon(Heroicon::OutlinedArrowDownTray)->url(fn (AccessoryHandover $r) => route('accessory-bast.download', $r)),
                Action::make('upload')->label('Upload')->icon(Heroicon::OutlinedArrowUpOnSquare)
                    ->visible(fn () => auth()->user()->can('manage inventory'))
                    ->modalHeading(fn (AccessoryHandover $r) => 'Upload scan BAST bertanda tangan — ' . $r->bast_number)
                    ->modalDescription('Setiap unggahan disimpan permanen dan tidak menimpa file sebelumnya.')->modalSubmitActionLabel('Simpan')
                    ->schema([
                        FileUpload::make('files')->label('Scan BAST (PDF / JPG / PNG)')->required()->multiple()->maxFiles(5)->disk('local')->directory('accessory-bast-signed')->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)->storeFileNamesIn('file_names'),
                        TextInput::make('note')->label('Keterangan (opsional)')->maxLength(250),
                    ])
                    ->action(function (array $data, AccessoryHandover $record, Action $action) {
                        Ui::run(function () use ($data, $record) {
                            $names = (array) ($data['file_names'] ?? []);
                            foreach ((array) $data['files'] as $key => $path) {
                                app(AccessoryHandoverService::class)->attachSigned($record, (string) $path, $names[$path] ?? ($names[$key] ?? null), $data['note'] ?? null);
                            }
                        }, 'Scan BAST diunggah dan diarsipkan', $action);
                    }),
                Action::make('files')->label('Arsip TTD')->icon(Heroicon::OutlinedFolderOpen)->visible(fn (AccessoryHandover $r) => $r->files_count > 0)
                    ->modalHeading(fn (AccessoryHandover $r) => 'Arsip scan BAST — ' . $r->bast_number)
                    ->modalContent(fn (AccessoryHandover $r) => view('filament.accessory-bast-files', ['handover' => $r->fresh()]))
                    ->modalWidth('3xl')->modalSubmitAction(false)->modalCancelActionLabel('Tutup'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccessoryHandovers::route('/'),
            'create' => CreateAccessoryHandover::route('/create'),
        ];
    }
}
