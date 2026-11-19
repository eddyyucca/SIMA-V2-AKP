<?php

namespace App\Filament\Pages\StockHistory;

use App\Filament\Support\StockHeaderActions;
use App\Filament\Support\StockHistoryTable;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Url;

/**
 * Riwayat Stok untuk satu jenis barang. Dibuka dari tombol "Riwayat Stok" di menu Accessories / Consumable / Komponen;
 * tidak muncul di side menu dan tidak ada halaman gabungan antar jenis.
 */
abstract class StockHistoryPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;
    protected string $view = 'filament.pages.stock-history';

    #[Url(as: 'tab')]
    public string $activeTab = 'all';

    /** @return class-string model barang (Accessory | Consumable | Component) */
    abstract protected static function itemClass(): string;

    /** URL menu barang (tujuan tombol kembali). */
    abstract protected static function menuUrl(): string;

    abstract protected static function menuLabel(): string;

    public static function shouldRegisterNavigation(): bool { return false; }

    public static function canAccess(): bool { return auth()->user()?->can('view inventory') ?? false; }

    public function getHeading(): string { return 'Riwayat Stok ' . static::menuLabel(); }

    public function getSubheading(): ?string { return 'Semua stok masuk, keluar, dan perubahan stok ' . static::menuLabel() . ' beserta dokumennya.'; }

    public function mount(): void
    {
        if (! array_key_exists($this->activeTab, StockHistoryTable::tabs(static::itemClass()))) $this->activeTab = 'all';
    }

    public function tabs(): array { return StockHistoryTable::tabs(static::itemClass()); }

    public function updatedActiveTab(): void
    {
        if (! array_key_exists($this->activeTab, $this->tabs())) $this->activeTab = 'all';
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return StockHistoryTable::configure($table, static::itemClass(), fn () => $this->activeTab);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label('← ' . static::menuLabel())->color('gray')->url(static::menuUrl()),
            StockHeaderActions::stockIn(static::itemClass()),
            StockHeaderActions::stockOut(static::itemClass()),
            ...$this->extraHeaderActions(),
        ];
    }

    /** @return list<Action> */
    protected function extraHeaderActions(): array { return []; }
}
