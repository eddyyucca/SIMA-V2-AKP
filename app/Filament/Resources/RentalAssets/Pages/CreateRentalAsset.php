<?php

namespace App\Filament\Resources\RentalAssets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\RentalAssets\RentalAssetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRentalAsset extends CreateRecord
{
    protected static string $resource = RentalAssetResource::class;
    protected static ?string $title = 'Tambah Aset Sewa';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'available';
        $data['ownership'] = 'rental';
        if (empty($data['serial_number'])) $data['serial_number'] = null;
        return CreateAsset::normalizeOwnership($data);
    }

    protected function getRedirectUrl(): string
    {
        return RentalAssetResource::getUrl('view', ['record' => $this->record]);
    }
}
