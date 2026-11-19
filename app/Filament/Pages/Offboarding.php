<?php

namespace App\Filament\Pages;

use App\Models\Asset;
use App\Models\Employee;
use App\Services\AssetService;
use App\Services\LicenseService;
use App\Services\StockService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class Offboarding extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;
    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';
    protected static ?int $navigationSort = 6;
    protected static ?string $title = 'Offboarding Karyawan';
    protected static ?string $slug = 'offboarding';
    protected string $view = 'filament.pages.offboarding';

    public ?int $employeeId = null;
    public array $returnAsset = [];
    public array $assetCond = [];
    public array $returnAcc = [];
    public array $accCond = [];

    public static function canAccess(): bool { return auth()->user()?->can('manage inventory') ?? false; }

    public function mount(): void
    {
        $this->employeeId = request('employee') ? (int) request('employee') : null;
        $this->prepare();
    }

    public function updatedEmployeeId(): void { $this->prepare(); }

    private function prepare(): void
    {
        $this->returnAsset = $this->assetCond = $this->returnAcc = $this->accCond = [];
        if (! $this->employeeId) return;
        foreach (Asset::where('employee_id', $this->employeeId)->get() as $a) {
            $this->returnAsset[$a->id] = true;
            $this->assetCond[$a->id] = $a->condition;
        }
        foreach (Employee::find($this->employeeId)?->heldAccessories() ?? [] as $h) {
            $this->returnAcc[$h['accessory']->id] = true;
            $this->accCond[$h['accessory']->id] = 'good';
        }
    }

    public function process(): void
    {
        $emp = Employee::findOrFail($this->employeeId);
        try {
            $items = [];
            foreach ($this->returnAsset as $id => $checked) {
                if ($checked) $items[$id] = $this->assetCond[$id] ?? 'good';
            }
            app(AssetService::class)->bulkCheckin($emp, $items);

            foreach ($emp->heldAccessories() as $h) {
                $id = $h['accessory']->id;
                if (! empty($this->returnAcc[$id])) {
                    app(StockService::class)->move($h['accessory'], 'return', $h['qty'], ['employee_id' => $emp->id, 'condition' => $this->accCond[$id] ?? 'good', 'reason' => 'Offboarding']);
                }
            }
            foreach ($emp->licenseSeats()->get() as $seat) {
                app(LicenseService::class)->revoke($seat);
            }
            $emp->refresh();
            $emp->update(['status' => 'resigned']);

            $left = $emp->assets()->count() + $emp->heldAccessories()->count();
            Notification::make()->title('Offboarding selesai')
                ->body($left ? "{$left} barang belum kembali dan akan ditandai di laporan." : 'Semua barang sudah kembali.')
                ->color($left ? 'warning' : 'success')->send();
            $this->prepare();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Gagal memproses offboarding')->body($e->getMessage())->danger()->send();
        }
    }
}
