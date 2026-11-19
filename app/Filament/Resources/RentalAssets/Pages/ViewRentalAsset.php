<?php

namespace App\Filament\Resources\RentalAssets\Pages;

use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\RentalAssets\RentalAssetResource;

/** Detail aset sewa di dalam menu Aset Sewa (isi dan aksi sama dengan halaman Aset). */
class ViewRentalAsset extends ViewAsset
{
    protected static string $resource = RentalAssetResource::class;
}
