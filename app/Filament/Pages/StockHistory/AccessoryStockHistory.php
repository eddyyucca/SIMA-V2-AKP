<?php

namespace App\Filament\Pages\StockHistory;

use App\Filament\Resources\Accessories\AccessoryResource;
use App\Models\Accessory;

class AccessoryStockHistory extends StockHistoryPage
{
    protected static ?string $slug = 'riwayat-stok-accessories';
    protected static ?string $title = 'Riwayat Stok Accessories';

    protected static function itemClass(): string { return Accessory::class; }
    protected static function menuUrl(): string { return AccessoryResource::getUrl('index'); }
    protected static function menuLabel(): string { return 'Accessories'; }
}
