<?php

namespace App\Filament\Pages;

use App\Models\Asset;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class OpnameSession extends Page
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'opname-session';
    protected static ?string $title = 'Sesi Stock Opname';
    protected string $view = 'filament.pages.opname-session';

    public int $opnameId = 0;
    public string $code = '';

    public static function canAccess(): bool { return auth()->user()?->can('manage inventory') ?? false; }

    public function mount(): void
    {
        $this->opnameId = (int) request('opname');
        StockOpname::findOrFail($this->opnameId);
    }

    public function getOpnameProperty(): StockOpname
    {
        return StockOpname::with('location')->findOrFail($this->opnameId);
    }

    public function onScan(string $code): void
    {
        $op = $this->opname;
        if ($op->status !== 'running') {
            Notification::make()->title('Sesi sudah ditutup')->warning()->send();
            return;
        }
        $code = Asset::extractCode($code);
        $asset = Asset::findByCode($code);
        if (! $asset) {
            Notification::make()->title("Kode tidak ditemukan: {$code}")->danger()->send();
            return;
        }
        $result = $asset->location_id === $op->location_id ? 'found' : 'wrong_location';
        StockOpnameItem::updateOrCreate(
            ['stock_opname_id' => $op->id, 'asset_id' => $asset->id],
            ['result' => $result, 'condition' => $asset->condition, 'scanned_at' => now(),
                'notes' => $result === 'wrong_location' ? 'Tercatat di lokasi: ' . ($asset->location?->name ?? '-') : null]
        );
        Notification::make()->title($asset->asset_tag . ($result === 'found' ? ' ditemukan' : ' — LOKASI BERBEDA'))->color($result === 'found' ? 'success' : 'warning')->send();
    }

    public function submitManual(): void { $this->onScan($this->code); $this->code = ''; }

    public function finish(): void
    {
        DB::transaction(function () {
            $op = StockOpname::query()->lockForUpdate()->findOrFail($this->opnameId);
            if ($op->status !== 'running') return;
            $seen = StockOpnameItem::where('stock_opname_id', $op->id)->pluck('asset_id');
            Asset::where('location_id', $op->location_id)->whereNotIn('status', Asset::INACTIVE)->whereNotIn('id', $seen)->get()
                ->each(fn ($a) => StockOpnameItem::create(['stock_opname_id' => $op->id, 'asset_id' => $a->id, 'result' => 'missing', 'condition' => $a->condition]));
            $op->update(['status' => 'finished', 'finished_at' => now()]);
        });
        Notification::make()->title('Sesi ditutup, laporan selisih tersedia')->success()->send();
    }
}
