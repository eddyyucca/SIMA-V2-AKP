<?php

namespace App\Filament\Resources\AccessoryHandovers\Pages;

use App\Filament\Resources\AccessoryHandovers\AccessoryHandoverResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccessoryHandovers extends ListRecords
{
    protected static string $resource = AccessoryHandoverResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Buat Serah Terima')];
    }
}
