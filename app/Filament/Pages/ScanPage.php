<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ScanPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;
    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';
    protected static ?int $navigationSort = 0;
    protected static ?string $navigationLabel = 'Scan QR';
    protected static ?string $title = 'Scan QR / Barcode';
    protected static ?string $slug = 'scan';
    protected string $view = 'filament.pages.scan';

    public string $code = '';

    /** Hasil scan aset terakhir: data aset + pemegang saat ini (null bila belum ada scan). */
    public ?array $card = null;

    /** Dibuka dari QR berbentuk alamat (?kode=...): langsung tampilkan kartunya. */
    public function mount(): void
    {
        $kode = request()->query('kode');
        if (is_string($kode) && trim($kode) !== '') {
            $this->code = trim($kode);
            $this->onScan($this->code);
        }
    }

    public static function canAccess(): bool { return auth()->user()?->can('view inventory') ?? false; }

    public function onScan(string $code): void
    {
        $found = \App\Services\LabelService::resolve($code);
        if (! $found) {
            Notification::make()->title('Kode tidak ditemukan: ' . trim($code))->danger()->send();
            return;
        }
        [$kind, $item] = $found;
        if ($kind === 'asset') {
            $this->card = static::assetCard($item);
            $this->code = '';
            return;
        }
        $this->card = null;
        $stock = $kind === 'consumable' ? rtrim(rtrim(number_format((float) $item->qty, 2, ',', '.'), '0'), ',') . ' ' . $item->unit : $item->qty_available . ' / ' . $item->qty_total;
        $index = match ($kind) {
            'accessory' => \App\Filament\Resources\Accessories\AccessoryResource::getUrl(),
            'consumable' => \App\Filament\Resources\Consumables\ConsumableResource::getUrl(),
            'component' => \App\Filament\Resources\Components\ComponentResource::getUrl(),
        };
        Notification::make()->title($item->code . ' — ' . $item->name)->body('Stok: ' . $stock)->success()
            ->actions([\Filament\Actions\Action::make('open')->label('Buka daftar')->url($index)])->persistent()->send();
    }

    /** Data yang tampil saat aset discan: identitas aset dan pemegangnya saat ini (nama, NIK, department, jabatan, sejak kapan). */
    public static function assetCard(Asset $a): array
    {
        $a->loadMissing(['category', 'location', 'employee.department']);
        $e = $a->employee;
        $since = null; $bast = null;
        if ($e) {
            $row = $a->assignments()->where('to_employee_id', $e->id)->whereIn('type', ['checkout', 'transfer'])->first();
            $since = $row?->assigned_at;
            $bast = $row?->bast_number;
        }
        return [
            'id' => $a->id, 'tag' => $a->asset_tag, 'category' => $a->category?->name, 'name' => trim(($a->brand ?? '') . ' ' . ($a->model ?? '')) ?: '-',
            'serial' => $a->serial_number ?: '-', 'status' => Asset::STATUSES[$a->status] ?? $a->status, 'status_key' => $a->status,
            'location' => $a->location?->name ?: '-', 'url' => AssetResource::getUrl('view', ['record' => $a]),
            'holder' => $e ? [
                'name' => $e->name, 'nik' => $e->nik ?: '-', 'department' => $e->department?->name ?: '-', 'position' => $e->position ?: '-',
                'resigned' => ! $e->isActive(), 'since' => $since?->format('d/m/Y'), 'bast' => $bast,
            ] : null,
        ];
    }

    public function submitManual(): void { $this->onScan($this->code); }
}
