<?php

namespace App\Filament\Resources\IpAddresses\Pages;

use App\Filament\Resources\IpAddresses\IpAddressResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageIpAddresses extends ManageRecords
{
    protected static string $resource = IpAddressResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
