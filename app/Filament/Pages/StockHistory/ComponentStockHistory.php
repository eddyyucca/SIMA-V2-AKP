<?php

namespace App\Filament\Pages\StockHistory;

use App\Filament\Resources\Components\ComponentResource;
use App\Filament\Support\ComponentInstallAction;
use App\Models\Component;

class ComponentStockHistory extends StockHistoryPage
{
    protected static ?string $slug = 'riwayat-stok-komponen';
    protected static ?string $title = 'Riwayat Stok Komponen';

    protected static function itemClass(): string { return Component::class; }
    protected static function menuUrl(): string { return ComponentResource::getUrl('index'); }
    protected static function menuLabel(): string { return 'Komponen'; }

    protected function extraHeaderActions(): array { return [ComponentInstallAction::header()]; }
}
