<?php

namespace App\Filament\Pages\StockHistory;

use App\Filament\Resources\Consumables\ConsumableResource;
use App\Models\Consumable;

class ConsumableStockHistory extends StockHistoryPage
{
    protected static ?string $slug = 'riwayat-stok-consumable';
    protected static ?string $title = 'Riwayat Stok Consumable';

    protected static function itemClass(): string { return Consumable::class; }
    protected static function menuUrl(): string { return ConsumableResource::getUrl('index'); }
    protected static function menuLabel(): string { return 'Consumable'; }
}
