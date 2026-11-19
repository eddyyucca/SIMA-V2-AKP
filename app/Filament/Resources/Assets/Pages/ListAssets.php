<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use Filament\Resources\Pages\ListRecords;

class ListAssets extends ListRecords
{
    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        // Tombol "Tambah aset" sudah ada di toolbar tabel (ikon +); tidak diduplikasi di header.
        return [];
    }
}
