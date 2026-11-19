<?php

namespace App\Filament\Resources\ArrivalPlans\Pages;

use App\Filament\Forms\SignaturePad;
use App\Filament\Resources\ArrivalPlans\ArrivalPlanResource;
use App\Filament\Support\Ui;
use App\Models\Accessory;
use App\Models\Asset;
use App\Services\ArrivalPlanService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewArrivalPlan extends ViewRecord
{
    protected static string $resource = ArrivalPlanResource::class;
    protected string $view = 'filament.arrival-plan';

    private function canManage(): bool { return auth()->user()->can('manage inventory'); }
    private function editable(): bool { return $this->canManage() && $this->record->isActive(); }

    /** Aset yang masih bebas: tersedia, tanpa pemegang, belum dicadangkan siapa pun. */
    public function assetOptions(): array
    {
        return Asset::with('location')->where('status', 'available')->whereNull('employee_id')->whereDoesntHave('reservationItem')->orderBy('asset_tag')->get()
            ->mapWithKeys(fn ($a) => [$a->id => "{$a->asset_tag} — {$a->brand} {$a->model}" . ($a->location ? " ({$a->location->name})" : '')])->all();
    }

    public function accessoryOptions(): array
    {
        $svc = app(ArrivalPlanService::class);
        return Accessory::orderBy('name')->get()->filter(fn ($c) => $c->qty_available - $svc->reservedQty($c) > 0)
            ->mapWithKeys(fn ($c) => [$c->id => $c->name . ' — bisa dicadangkan: ' . ($c->qty_available - $svc->reservedQty($c))])->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addAsset')->label('Tambah Aset')->icon(Heroicon::OutlinedComputerDesktop)
                ->visible(fn () => $this->editable())
                ->schema([Select::make('asset_id')->label('Unit aset (yang masih bebas)')->required()->searchable()->options(fn () => $this->assetOptions())])
                ->action(function (array $data, Action $action) {
                    Ui::run(fn () => app(ArrivalPlanService::class)->addAsset($this->record, Asset::findOrFail($data['asset_id'])), 'Aset dicadangkan untuk rencana ini', $action);
                    $this->record->refresh()->load('items');
                }),
            Action::make('addAccessory')->label('Tambah Accessory')->icon(Heroicon::OutlinedCursorArrowRays)
                ->visible(fn () => $this->editable())
                ->schema([
                    Select::make('accessory_id')->label('Accessory')->required()->searchable()->options(fn () => $this->accessoryOptions()),
                    TextInput::make('qty')->label('Jumlah')->numeric()->maxValue(1000000)->integer()->minValue(1)->default(1)->required(),
                ])
                ->action(function (array $data, Action $action) {
                    Ui::run(fn () => app(ArrivalPlanService::class)->addAccessory($this->record, Accessory::findOrFail($data['accessory_id']), (int) $data['qty']), 'Accessory dicadangkan', $action);
                    $this->record->refresh()->load('items');
                }),
            Action::make('handover')->label('Serahkan Semua (Karyawan Tiba)')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn () => $this->editable() && $this->record->items()->whereIn('status', ['planned', 'prepared', 'ready'])->exists())
                ->modalHeading('Karyawan tiba: serahkan barang')
                ->modalDescription('Data karyawan dibuat otomatis, lalu setiap aset diserahkan dengan BAST sendiri dan stok accessories dikurangi.')
                ->fillForm(fn () => ['email' => $this->record->email, 'condition' => 'good'])
                ->schema([
                    TextInput::make('email')->label('Email karyawan')->email()->required()->helperText('Dipakai mengirim BAST dan sebagai identitas karyawan.'),
                    Select::make('condition')->label('Kondisi barang saat diserahkan')->options(Asset::CONDITIONS)->default('good')->required(),
                    Toggle::make('partial')->label('Serahkan yang sudah siap saja; barang lain dilepas dari rencana')
                        ->visible(fn () => $this->record->progress()['ready'] < $this->record->progress()['total']),
                    SignaturePad::make('signature_receiver')->label('Tanda tangan penerima'),
                    SignaturePad::make('signature_it')->label('Tanda tangan Staff IT'),
                ])
                ->action(function (array $data, Action $action) {
                    Ui::run(fn () => app(ArrivalPlanService::class)->handover($this->record, $data), 'Serah terima selesai: karyawan dibuat, BAST terbit, stok diperbarui', $action);
                    $this->record->refresh()->load('items');
                }),
            Action::make('reschedule')->label('Ubah Tanggal Tiba')->icon(Heroicon::OutlinedCalendar)
                ->visible(fn () => $this->editable())
                ->fillForm(fn () => ['arrival_date' => $this->record->arrival_date->toDateString()])
                ->schema([DatePicker::make('arrival_date')->label('Tanggal tiba baru')->required()->minDate(today())])
                ->action(function (array $data, Action $action) {
                    Ui::run(fn () => app(ArrivalPlanService::class)->reschedule($this->record, $data['arrival_date']), 'Tanggal tiba diubah', $action);
                    $this->record->refresh();
                }),
            Action::make('cancel')->label('Batalkan Rencana')->icon(Heroicon::OutlinedXCircle)->color('danger')->requiresConfirmation()
                ->modalDescription('Semua barang yang dicadangkan dilepas dan bisa dipakai orang lain.')
                ->visible(fn () => $this->editable())
                ->schema([Textarea::make('reason')->label('Alasan pembatalan')])
                ->action(function (array $data, Action $action) {
                    Ui::run(fn () => app(ArrivalPlanService::class)->cancel($this->record, $data['reason'] ?? null), 'Rencana dibatalkan, pencadangan dilepas', $action);
                    $this->record->refresh()->load('items');
                }),
            EditAction::make()->visible(fn () => $this->editable()),
        ];
    }

    /** Dipanggil dari tombol pada daftar barang: planned / prepared / ready. */
    public function markItem(int $itemId, string $status): void
    {
        abort_unless($this->editable(), 403);
        try {
            app(ArrivalPlanService::class)->setItemStatus($this->record->items()->findOrFail($itemId), $status);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Gagal mengubah status barang')->body($e->getMessage())->danger()->send();
        }
        $this->record->refresh()->load('items');
    }

    public function removeItem(int $itemId): void
    {
        abort_unless($this->editable(), 403);
        try {
            app(ArrivalPlanService::class)->removeItem($this->record->items()->findOrFail($itemId));
            Notification::make()->title('Barang dikeluarkan dari rencana, pencadangan dilepas')->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Gagal menghapus barang')->body($e->getMessage())->danger()->send();
        }
        $this->record->refresh()->load('items');
    }
}
