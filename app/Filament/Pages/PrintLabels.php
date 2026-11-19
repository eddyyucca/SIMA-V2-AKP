<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Services\LabelService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class PrintLabels extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;
    protected static string|UnitEnum|null $navigationGroup = 'Inventaris';
    protected static ?int $navigationSort = 8;
    protected static ?string $navigationLabel = 'Cetak Label';
    protected static ?string $title = 'Cetak Label (Brother PT-D610BT)';
    protected static ?string $slug = 'cetak-label';
    protected string $view = 'filament.pages.print-labels';

    public string $kind = 'asset';
    public string $search = '';
    public int $tape = 24;
    public int $length = 60;
    public int $copies = 1;
    public string $orient = 'landscape';
    public array $selected = [];

    public static function canAccess(): bool { return auth()->user()?->can('view inventory') ?? false; }

    public function mount(): void
    {
        $k = request('kind');
        if ($k && isset(LabelService::KINDS[$k])) $this->kind = $k;
        $this->applyDefaults();
        if ($ids = request('ids')) $this->selected = array_map('intval', explode(',', $ids));
    }

    public function updatedKind(): void { $this->selected = []; $this->search = ''; $this->applyDefaults(); }

    /** Aset: pita 24 mm berbingkai. Accessories/Komponen: pita 9 mm (Consumable tidak dilabeli). */
    private function applyDefaults(): void
    {
        $d = LabelService::defaults($this->kind);
        $this->tape = $d['tape'];
        $this->length = $d['length'];
    }

    public function rows()
    {
        $m = LabelService::model($this->kind);
        $q = $m::query()->orderBy('id');
        if ($this->search !== '') {
            $s = '%' . $this->search . '%';
            $q->where(fn ($w) => $this->kind === 'asset'
                ? $w->where('asset_tag', 'like', $s)->orWhere('brand', 'like', $s)->orWhere('model', 'like', $s)->orWhere('serial_number', 'like', $s)
                : $w->where('name', 'like', $s));
        }
        return $q->limit(300)->get();
    }

    public function selectAll(): void { $this->selected = $this->rows()->pluck('id')->map(fn ($i) => (int) $i)->all(); }
    public function clear(): void { $this->selected = []; }

    public function url(): ?string
    {
        if (! $this->selected) return null;
        return route('labels.print', ['kind' => $this->kind, 'ids' => implode(',', array_map('intval', $this->selected)), 'tape' => $this->tape, 'length' => $this->length, 'copies' => $this->copies, 'orient' => $this->orient]);
    }
}
