<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Forms\SignaturePad;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Components\ComponentResource;
use App\Filament\Support\Ui;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Component;
use App\Models\Disposal;
use App\Models\Employee;
use App\Models\Location;
use App\Services\AssetService;
use App\Services\ComponentService;
use App\Services\NotificationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ViewAsset extends ViewRecord
{
    protected static string $resource = AssetResource::class;
    protected string $view = 'filament.asset-detail';

    private function can(): bool { return auth()->user()->can('manage inventory'); }

    private function handoverFields(bool $withEmployee, string $label = 'Penerima'): array
    {
        return array_values(array_filter([
            $withEmployee ? Select::make('employee_id')->label('Karyawan penerima')->searchable()->required()
                ->options(fn () => Employee::where('status', 'active')->pluck('name', 'id')) : null,
            Select::make('condition')->label('Kondisi saat ini')->options(Asset::CONDITIONS)->default('good')->required(),
            \App\Filament\Support\QuickAdd::location(Select::make('location_id')->label('Lokasi')->options(fn () => Location::pluck('name', 'id'))),
            Textarea::make('notes')->label('Catatan'),
            $withEmployee ? Toggle::make('borrowed')->label('Peminjaman sementara (status Dipinjam)') : null,
            SignaturePad::make('signature_receiver')->label("Tanda tangan {$label}"),
            SignaturePad::make('signature_it')->label('Tanda tangan Staff IT'),
        ]));
    }

    private function hasBastDocs(): bool
    {
        return $this->record->assignments()->exists() || $this->record->bastFiles()->exists();
    }

    protected function getHeaderActions(): array
    {
        /** @var Asset $r */
        return [
            ActionGroup::make(\App\Filament\Support\AgentActions::make(fn () => $this->record))->label('Agent')->icon(Heroicon::OutlinedComputerDesktop)->button()->color('gray')
                ->visible(fn () => $this->can()),
            Action::make('checkout')->label('Checkout')->icon(Heroicon::OutlinedArrowUpTray)->color('primary')
                ->visible(fn () => $this->can() && $this->record->status === 'available')
                ->schema(fn () => $this->handoverFields(true))
                ->fillForm(fn () => ['condition' => $this->record->condition, 'location_id' => $this->record->location_id])
                ->action(function (array $data, Action $action) {
                    Ui::run(fn () => app(AssetService::class)->checkout($this->record, Employee::findOrFail($data['employee_id']), $data), 'Serah terima dicatat, BAST dibuat', $action);
                    $this->record->refresh();
                }),
            ActionGroup::make([
                Action::make('bast_file')->label('File')->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn () => $this->hasBastDocs())
                    ->modalHeading(fn () => 'Arsip BAST — ' . $this->record->asset_tag)
                    ->modalContent(fn () => view('filament.asset-bast-files', ['asset' => $this->record->fresh()]))
                    ->modalWidth('4xl')->modalSubmitAction(false)->modalCancelActionLabel('Tutup'),
                Action::make('bast_upload')->label('Upload')->icon(Heroicon::OutlinedArrowUpOnSquare)
                    ->visible(fn () => $this->can())
                    ->modalHeading('Upload PDF BAST bertanda tangan')
                    ->modalDescription('Setiap unggahan disimpan permanen dan tidak menimpa file sebelumnya. Unggah BAST terbaru setiap ada mutasi.')
                    ->modalSubmitActionLabel('Simpan')
                    ->fillForm(fn () => ['assignment_id' => $this->record->assignments()->orderByDesc('assigned_at')->orderByDesc('id')->value('id')])
                    ->schema([
                        Select::make('assignment_id')->label('Untuk BAST (opsional)')->native(false)->placeholder('Tidak terkait BAST tertentu')
                            ->options(fn () => $this->record->assignments()->orderByDesc('assigned_at')->orderByDesc('id')->get()
                                ->mapWithKeys(fn ($x) => [$x->id => $x->bast_number . ' — ' . AssetAssignment::TYPES[$x->type] . ' ' . $x->assigned_at->format('d/m/Y')])->all()),
                        TextInput::make('note')->label('Keterangan (opsional)')->maxLength(250)->placeholder('mis. Mutasi dari Andi ke Budi'),
                        FileUpload::make('files')->label('File PDF BAST')->required()->multiple()->maxFiles(10)->disk('local')->directory('bast-signed')->visibility('private')
                            ->acceptedFileTypes(['application/pdf'])->maxSize(10240)->helperText('Format PDF, maksimal 10 MB per file, boleh beberapa file sekaligus.'),
                    ])
                    ->action(function (array $data, Action $action) {
                        Ui::run(function () use ($data) {
                            $a = ! empty($data['assignment_id']) ? AssetAssignment::findOrFail($data['assignment_id']) : null;
                            foreach ((array) $data['files'] as $path) {
                                app(AssetService::class)->attachSignedBast($this->record, $a, (string) $path, $data['note'] ?? null);
                            }
                        }, 'PDF BAST diunggah dan diarsipkan', $action);
                        $this->record->refresh();
                    }),
                Action::make('bast_print')->label('Cetak')->icon(Heroicon::OutlinedPrinter)
                    ->visible(fn () => $this->can())
                    ->modalHeading('Cetak formulir BAST')
                    ->modalDescription('Formulir berisi data aset ini, siap dicetak dan ditandatangani. Tidak mengubah status atau pemegang aset.')
                    ->modalSubmitActionLabel('Buat PDF')
                    ->fillForm(fn () => ['type' => $this->record->employee_id ? 'transfer' : 'checkout'])
                    ->schema([
                        Select::make('type')->label('Jenis BAST')->required()->native(false)->options(AssetAssignment::TYPES),
                        Select::make('employee_id')->label('Karyawan penerima / pengembali (opsional, kosongkan untuk diisi tulisan tangan)')->searchable()
                            ->options(fn () => Employee::where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                    ])
                    ->action(function (array $data, Action $action) {
                        try {
                            $pdf = app(AssetService::class)->bastFormPdf($this->record, $data['type'], ! empty($data['employee_id']) ? Employee::find($data['employee_id']) : null);
                        } catch (\InvalidArgumentException $e) {
                            \Filament\Notifications\Notification::make()->title('Gagal membuat BAST aset')->body($e->getMessage())->danger()->send();
                            return null;
                        }
                        return response()->streamDownload(fn () => print($pdf), 'Formulir-BAST-' . str_replace('/', '-', $this->record->asset_tag) . '.pdf', ['Content-Type' => 'application/pdf']);
                    }),
            ])->label('BAST')->icon(Heroicon::OutlinedDocumentText)->button()->color('gray')->visible(fn () => $this->can() || $this->hasBastDocs()),
            Action::make('contract_upload')->label('Upload Kontrak Sewa')->icon(Heroicon::OutlinedDocumentArrowUp)
                ->visible(fn () => $this->can() && $this->record->ownership === 'rental')
                ->modalHeading('Upload PDF kontrak sewa')
                ->modalDescription('Ketik jenis kontrak (mis. Kontrak Utama, Addendum, SLA); jumlahnya tidak dibatasi. Untuk pembaruan tahunan, pilih "Pembaruan tahunan" dan isi periode barunya. Berkas lama tetap tersimpan.')
                ->modalSubmitActionLabel('Simpan')
                ->fillForm(function () {
                    $last = $this->record->load('rentalContracts')->currentContracts()->first()?->first();
                    return ['label' => $last?->label ?? 'Kontrak Utama', 'kind' => $last ? 'renewal' : 'initial', 'contract_no' => $last?->contract_no ?? $this->record->rental_contract_no,
                        'start_date' => $last ? $last->end_date->copy()->addDay()->toDateString() : $this->record->rental_start?->toDateString(),
                        'end_date' => $last ? $last->end_date->copy()->addYear()->toDateString() : $this->record->rental_end?->toDateString(),
                        'update_rental_end' => ! $last];
                })
                ->schema([
                    TextInput::make('label')->label('Jenis kontrak')->required()->maxLength(60)->datalist(fn () => collect(\App\Models\RentalContract::LABEL_SUGGESTIONS)
                        ->merge($this->record->rentalContracts->pluck('label'))->unique()->values()->all())
                        ->helperText('Ketik sendiri atau pilih saran. Nama yang sama = riwayat/pembaruan dari kontrak yang sama.'),
                    Select::make('kind')->label('Jenis')->options(\App\Models\RentalContract::KINDS)->required()->native(false),
                    TextInput::make('contract_no')->label('No. kontrak')->maxLength(255),
                    \Filament\Forms\Components\DatePicker::make('start_date')->label('Periode mulai')->required(),
                    \Filament\Forms\Components\DatePicker::make('end_date')->label('Periode berakhir')->required()->afterOrEqual('start_date'),
                    TextInput::make('note')->label('Keterangan (opsional)')->maxLength(250),
                    Toggle::make('update_rental_end')->label('Perbarui tanggal akhir sewa aset mengikuti periode ini')->helperText('Aktifkan untuk kontrak utama saja.'),
                    FileUpload::make('file')->label('File PDF kontrak')->required()->storeFileNamesIn('file_name')->disk('local')->directory('rental-contracts')->visibility('private')
                        ->acceptedFileTypes(['application/pdf'])->maxSize(10240)->helperText('Format PDF, maksimal 10 MB.'),
                ])
                ->action(function (array $data, Action $action) {
                    Ui::run(fn () => app(AssetService::class)->addRentalContract($this->record, [
                        'label' => $data['label'], 'update_rental_end' => (bool) ($data['update_rental_end'] ?? false), 'kind' => $data['kind'], 'contract_no' => $data['contract_no'] ?? null,
                        'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'note' => $data['note'] ?? null,
                        'original_name' => is_array($data['file_name'] ?? null) ? (string) reset($data['file_name']) : ($data['file_name'] ?? null),
                        'path' => is_array($data['file']) ? (string) reset($data['file']) : (string) $data['file'],
                    ]), 'Kontrak sewa diunggah dan diarsipkan', $action);
                    $this->record->refresh();
                }),
            Action::make('transfer')->label('Mutasi')->icon(Heroicon::OutlinedArrowsRightLeft)
                ->visible(fn () => $this->can() && in_array($this->record->status, ['deployed', 'borrowed']) && $this->record->employee_id)
                ->schema(fn () => $this->handoverFields(true))
                ->fillForm(fn () => ['condition' => $this->record->condition, 'location_id' => $this->record->location_id])
                ->action(function (array $data, Action $action) {
                    Ui::run(fn () => app(AssetService::class)->transfer($this->record, Employee::findOrFail($data['employee_id']), $data), 'Mutasi dicatat, BAST baru dibuat', $action);
                    $this->record->refresh();
                }),
            Action::make('checkin')->label('Checkin')->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn () => $this->can() && $this->record->employee_id)
                ->schema(fn () => $this->handoverFields(false, 'Pengembali'))
                ->fillForm(fn () => ['condition' => $this->record->condition, 'location_id' => $this->record->location_id])
                ->action(function (array $data, Action $action) {
                    Ui::run(fn () => app(AssetService::class)->checkin($this->record, $data), 'Pengembalian dicatat, BAST dibuat', $action);
                    $this->record->refresh();
                }),
            ActionGroup::make([
                Action::make('install')->label('Pasang Komponen')->icon(Heroicon::OutlinedCpuChip)
                    ->modalDescription('Untuk upgrade / perbaikan. Membuat dokumen bukti pemasangan; aset yang belum ada pemegangnya juga bisa dipasangi.')
                    ->schema(fn () => \App\Filament\Support\ComponentInstallAction::fields(true, false, $this->record->id))
                    ->action(function (array $data, Action $action) {
                        \App\Filament\Support\ComponentInstallAction::run($action, $this->record, $data);
                        $this->record->refresh();
                    }),
                Action::make('uninstall')->label('Lepas Komponen')->icon(Heroicon::OutlinedMinusCircle)
                    ->visible(fn () => $this->record->installedComponents()->isNotEmpty())
                    ->modalDescription('Komponen yang dilepas dicatat di dokumen. Yang baik kembali ke stok, yang rusak dihapus dari stok.')
                    ->schema([
                        Select::make('component_id')->label('Komponen')->required()->native(false)
                            ->options(fn () => $this->record->installedComponents()->mapWithKeys(fn ($r) => [$r['component']->id => "{$r['component']->name} (terpasang " . (int) $r['qty'] . ')'])->all()),
                        TextInput::make('qty')->label('Jumlah')->numeric()->maxValue(1000000)->integer()->minValue(1)->default(1)->required(),
                        Select::make('reason')->label('Alasan')->options(\App\Models\AccessoryHandover::INSTALL_REASONS)->required()->native(false),
                        Select::make('condition')->label('Kondisi komponen')->native(false)->default('good')
                            ->options(['good' => 'Baik — kembali ke stok tersedia', 'broken' => 'Rusak — dihapus dari stok (tidak kembali)']),
                        Textarea::make('note')->label('Catatan (mis. rusak)')->rows(2),
                    ])
                    ->action(function (array $data, Action $action) {
                        \App\Filament\Support\ComponentInstallAction::run($action, $this->record, [
                            'old_id' => $data['component_id'], 'old_qty' => $data['qty'], 'old_condition' => $data['condition'] ?? 'good', 'old_note' => $data['note'] ?? null, 'reason' => $data['reason'],
                        ], 'Komponen dilepas');
                        $this->record->refresh();
                    }),
                Action::make('damage')->label('Catat Kerusakan')->icon(Heroicon::OutlinedExclamationTriangle)->color('warning')
                    ->schema([Textarea::make('issue')->label('Kerusakan')->required()])
                    ->action(function (array $data, Action $action) {
                        Ui::run(fn () => app(AssetService::class)->reportDamage($this->record, $this->record->employee, $data['issue']), 'Perbaikan dicatat, status: Dalam Perbaikan', $action);
                        $this->record->refresh();
                    }),
                Action::make('lost')->label('Tandai Hilang')->icon(Heroicon::OutlinedQuestionMarkCircle)->color('danger')->requiresConfirmation()
                    ->visible(fn () => ! in_array($this->record->status, ['lost', 'disposed', 'returned']))
                    ->action(function () { $this->record->update(['status' => 'lost']); $this->record->refresh(); }),
                Action::make('dispose')->label('Ajukan Disposal')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn () => $this->record->ownership === 'owned' && $this->record->status !== 'disposed' && ! $this->record->disposals()->where('status', 'pending')->exists())
                    ->schema([
                        Textarea::make('reason')->label('Alasan')->required(),
                        Toggle::make('data_wiped')->label('Data pada perangkat sudah di-wipe')->accepted()->validationMessages(['accepted' => 'Wipe data wajib sebelum pengajuan.']),
                    ])
                    ->action(function (array $data, Action $action) {
                        Ui::run(fn () => app(\App\Services\DisposalService::class)->request($this->record->id, $data['reason'], (bool) $data['data_wiped']), 'Pengajuan disposal dikirim', $action);
                    }),
                Action::make('return_vendor')->label('Kembalikan ke Vendor')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
                    ->visible(fn () => $this->record->ownership === 'rental' && $this->record->status !== 'returned')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('returned_at')->label('Tanggal dikembalikan')->default(now())->required(),
                        Select::make('condition')->label('Kondisi saat dikembalikan')->options(Asset::CONDITIONS)->default('good')->required(),
                        Toggle::make('data_wiped')->label('Data pada perangkat sudah di-wipe (wajib)')->accepted()->validationMessages(['accepted' => 'Wipe data wajib sebelum dikembalikan.']),
                        Textarea::make('notes')->label('Catatan / no. berita acara pengembalian'),
                    ])
                    ->action(function (array $data, Action $action) {
                        Ui::run(fn () => app(AssetService::class)->returnToVendor($this->record, $data), 'Aset dikembalikan ke vendor', $action);
                        $this->record->refresh();
                    }),
                Action::make('extend_rental')->label('Perpanjang Sewa')->icon(Heroicon::OutlinedCalendarDays)
                    ->visible(fn () => $this->record->ownership === 'rental' && $this->record->status !== 'returned')
                    ->fillForm(fn () => ['rental_end' => $this->record->rental_end?->copy()->addYear()->toDateString(), 'monthly' => $this->record->rental_monthly_cost])
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('rental_end')->label('Berakhir pada')->required(),
                        TextInput::make('monthly')->label('Biaya per bulan (kosongkan jika tetap)')->numeric()->minValue(0)->maxValue(100000000000)->prefix('Rp'),
                    ])
                    ->action(function (array $data, Action $action) {
                        Ui::run(fn () => app(AssetService::class)->extendRental($this->record, (string) $data['rental_end'], isset($data['monthly']) && $data['monthly'] !== '' ? (float) $data['monthly'] : null), 'Masa sewa diperpanjang', $action);
                        $this->record->refresh();
                    }),
                Action::make('label')->label('Cetak Label (Brother)')->icon(Heroicon::OutlinedPrinter)
                    ->url(fn () => route('labels.print', ['kind' => 'asset', 'ids' => $this->record->id]), shouldOpenInNewTab: true),
            ])->label('Lainnya')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray')->visible(fn () => $this->can()),
            EditAction::make()->color('gray')->visible(fn () => $this->can()),
        ];
    }
}
