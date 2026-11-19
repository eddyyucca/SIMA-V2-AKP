<?php

namespace App\Filament\Resources\Consumables\Pages;

use App\Filament\Resources\Consumables\ConsumableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageConsumables extends ManageRecords
{
    protected static string $resource = ConsumableResource::class;

    protected function getHeaderActions(): array
    {
        return [...\App\Filament\Support\StockHeaderActions::make(\App\Models\Consumable::class), CreateAction::make()];
    }
}
