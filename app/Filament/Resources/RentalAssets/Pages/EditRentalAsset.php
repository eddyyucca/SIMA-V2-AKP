<?php

namespace App\Filament\Resources\RentalAssets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\RentalAssets\RentalAssetResource;

class EditRentalAsset extends EditAsset
{
    protected static string $resource = RentalAssetResource::class;

    /** Kembali ke detail; bila kepemilikan diubah jadi milik sendiri, detailnya ada di menu Aset. */
    protected function getRedirectUrl(): string
    {
        return $this->record->ownership === 'rental'
            ? RentalAssetResource::getUrl('view', ['record' => $this->record])
            : AssetResource::getUrl('view', ['record' => $this->record]);
    }
}
