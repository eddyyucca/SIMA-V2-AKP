<?php

namespace App\Filament\Resources\Components\Pages;

use App\Filament\Resources\Components\ComponentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageComponents extends ManageRecords
{
    protected static string $resource = ComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [...\App\Filament\Support\StockHeaderActions::make(\App\Models\Component::class), \App\Filament\Support\ComponentInstallAction::header(), CreateAction::make()];
    }
}
